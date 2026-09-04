"""End-to-end check that `signature_policy: strict` actually gates requests.

The default conformance run uses `log`, which means it exercises **zero** signature
verification code -- the exact class of blind spot that let the SDK ship DER signatures no
conformant peer could verify while every check was green. This is the pass that closes it.

Why the signing is done here rather than by the SDK
---------------------------------------------------
The merchant verifies inbound signatures against the keys published in the *agent's*
platform profile, and this script is the agent: it generates its own P-256 key, publishes
the public half as a JWK, and signs with the private half using `cryptography` and a
signature base assembled by hand from RFC 9421 §2.5. Nothing the SDK wrote is involved in
producing the signature.

That matters. Driving the SDK's own signer against its own verifier reproduces the closed
loop this whole exercise exists to break: it would agree with itself about a base that
nobody else computes the same way. Assembling the base independently is what makes a pass
here mean something.

What it does not claim
----------------------
Two implementations agreeing is weaker evidence than a published test vector, and this one
was written against the same RFC by the same person as the code under test, so a shared
misreading would go unnoticed. The crypto primitives are pinned separately against
external RFC 9421 fixtures; what this adds is that the *wiring* holds end to end over real
HTTP -- strict refuses what it should and accepts what it should, through the routing,
the request listener, the profile fetch and the verifier in the order a real request meets
them.
"""

from __future__ import annotations

import base64
import hashlib
import json
import sys
import threading
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer

from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec, utils as asym_utils

PROFILE_PATH = "/.well-known/ucp"
KEY_ID = "conformance-agent-key"
ALGORITHM = "ecdsa-p256-sha256"


def b64url(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).decode().rstrip("=")


class Agent:
    """A platform that signs its requests, standing in for a real agent."""

    def __init__(self) -> None:
        self.private_key = ec.generate_private_key(ec.SECP256R1())

    def jwk(self) -> dict[str, str]:
        numbers = self.private_key.public_key().public_numbers()

        return {
            "kty": "EC",
            "crv": "P-256",
            "kid": KEY_ID,
            "alg": "ES256",
            "use": "sig",
            # Fixed 32-byte width. A coordinate that happens to have leading zero bytes
            # must still be 32 bytes, or the key the merchant reconstructs is not this one.
            "x": b64url(numbers.x.to_bytes(32, "big")),
            "y": b64url(numbers.y.to_bytes(32, "big")),
        }

    def profile(self, version: str) -> dict[str, object]:
        # `keys` is a top-level sibling of `ucp`, not a member of it: 2026-08-25 promoted it
        # to a JWK Set at the profile root, which is also what lets the same document serve
        # as a Web Bot Auth key source.
        return {
            "ucp": {
                "version": version,
                "services": {},
                "capabilities": {},
                "payment_handlers": {},
            },
            "keys": [self.jwk()],
        }

    def sign(self, method: str, url: str, body: bytes) -> dict[str, str]:
        """Build the RFC 9421 signature base by hand and sign it.

        The covered set names `content-digest` whenever there is a body. Leaving it out
        while sending one is a body-swap primitive -- the signature would still verify over
        method and target while nothing attested to the bytes -- and the SDK rejects that,
        which `test_body_unattested` below relies on.
        """
        components = ["@method", "@target-uri"]
        headers: dict[str, str] = {}

        if body:
            digest = b64url_std(hashlib.sha256(body).digest())
            headers["Content-Digest"] = f"sha-256=:{digest}:"
            components.append("content-digest")

        created = int(time.time())
        # `expires` is required, not optional: the SDK refuses a signature that never
        # stops being valid, since a captured one would otherwise be replayable forever.
        expires = created + 60
        covered = " ".join(f'"{component}"' for component in components)
        params = (
            f'({covered});created={created};expires={expires}'
            f';keyid="{KEY_ID}";alg="{ALGORITHM}"'
        )

        lines = []
        for component in components:
            if component == "@method":
                lines.append(f'"@method": {method}')
            elif component == "@target-uri":
                lines.append(f'"@target-uri": {url}')
            else:
                lines.append(f'"content-digest": {headers["Content-Digest"]}')
        lines.append(f'"@signature-params": {params}')
        base = "\n".join(lines).encode()

        der = self.private_key.sign(base, ec.ECDSA(hashes.SHA256()))
        r, s = asym_utils.decode_dss_signature(der)
        # Fixed-width r||s, not DER. This is the deviation the SDK shipped until 0.0.6, so
        # emitting DER here would let a regression pass unnoticed.
        raw = r.to_bytes(32, "big") + s.to_bytes(32, "big")

        headers["Signature-Input"] = f"sig={params}"
        headers["Signature"] = f"sig=:{b64url_std(raw)}:"

        return headers


def b64url_std(raw: bytes) -> str:
    """Standard base64, which is what RFC 9421 byte-sequence fields use."""
    return base64.b64encode(raw).decode()


class ProfileServer:
    def __init__(self, profile: dict[str, object]) -> None:
        payload = json.dumps(profile).encode()

        class Handler(BaseHTTPRequestHandler):
            def do_GET(self) -> None:  # noqa: N802 - required by BaseHTTPRequestHandler
                if self.path != PROFILE_PATH:
                    self.send_error(404)
                    return
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def log_message(self, *args: object) -> None:
                return

        self.httpd = HTTPServer(("127.0.0.1", 0), Handler)
        self.port = self.httpd.server_port
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)

    def __enter__(self) -> ProfileServer:
        self.thread.start()

        return self

    def __exit__(self, *args: object) -> None:
        self.httpd.shutdown()

    @property
    def profile_url(self) -> str:
        return f"http://127.0.0.1:{self.port}{PROFILE_PATH}"


def request(url: str, body: bytes, headers: dict[str, str]) -> tuple[int, dict[str, object]]:
    req = urllib.request.Request(url, data=body, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=10) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        raw = error.read()
        try:
            return error.code, json.loads(raw or b"{}")
        except json.JSONDecodeError:
            return error.code, {"raw": raw.decode(errors="replace")}


def describes_a_ucp_error(payload: dict[str, object]) -> bool:
    """A refusal has to be a UCP error descriptor, not a stack trace or bare HTML.

    An agent that cannot parse the refusal cannot tell a signature problem from an outage,
    and retrying an unsigned request forever is the behaviour that produces.
    """
    messages = payload.get("messages")
    if not isinstance(messages, list) or not messages:
        return False

    return all(
        isinstance(entry, dict) and "code" in entry and "severity" in entry
        for entry in messages
    )


def main() -> int:
    server_url = sys.argv[1].rstrip("/")
    version = json.load(urllib.request.urlopen(f"{server_url}{PROFILE_PATH}", timeout=10))
    version = version.get("ucp", version).get("version")
    if not isinstance(version, str):
        return fail("discovery did not advertise a protocol version")

    agent = Agent()
    failures: list[str] = []

    with ProfileServer(agent.profile(version)) as profiles:
        url = f"{server_url}/ucp/v1/catalog/search"
        body = json.dumps({"query": "tent"}).encode()
        base_headers = {
            "Content-Type": "application/json",
            "UCP-Agent": f'platform; profile="{profiles.profile_url}"',
        }

        # 1. Unsigned. Strict has to refuse it, and say so in a shape an agent can read.
        status, payload = request(url, body, dict(base_headers))
        if status < 400:
            failures.append(f"unsigned request was accepted with {status}")
        elif not describes_a_ucp_error(payload):
            failures.append(f"unsigned refusal was not a UCP error descriptor: {payload}")
        else:
            print(f"  unsigned request refused with {status} and a UCP error descriptor")

        # 2. Correctly signed. Has to be accepted, or strict mode is simply an outage.
        headers = dict(base_headers)
        headers.update(agent.sign("POST", url, body))
        status, payload = request(url, body, headers)
        if status >= 400:
            failures.append(f"correctly signed request was refused with {status}: {payload}")
        else:
            print(f"  correctly signed request accepted with {status}")

        # 3. A body the signature does not cover. Dropping content-digest from the covered
        #    set while still sending a body leaves the bytes unattested, so it must be
        #    refused rather than verified over method and target alone.
        headers = dict(base_headers)
        headers.update(agent.sign("POST", url, b""))
        status, payload = request(url, body, headers)
        if status < 400:
            failures.append(f"a request whose signature did not cover its body was accepted with {status}")
        else:
            print(f"  body outside the covered components refused with {status}")

        # 4. A tampered signature. Guards against the pass above succeeding for any reason
        #    other than the signature actually being checked.
        headers = dict(base_headers)
        headers.update(agent.sign("POST", url, body))
        good = headers["Signature"]
        headers["Signature"] = good[:-3] + ("AA:" if not good[:-3].endswith("AA") else "BB:")
        status, payload = request(url, body, headers)
        if status < 400:
            failures.append(f"a tampered signature was accepted with {status}")
        else:
            print(f"  tampered signature refused with {status}")

    for failure in failures:
        print(f"  FAIL {failure}")

    return 1 if failures else 0


def fail(message: str) -> int:
    print(f"  FAIL {message}")

    return 1


if __name__ == "__main__":
    sys.exit(main())
