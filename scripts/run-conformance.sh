#!/usr/bin/env bash
#
# Runs the upstream UCP conformance suite against the merchant example app.
#
# The suite is the only check on this SDK that was not written by this repository. Everything
# else here validates the SDK against schemas it flattened itself, using a validator it wrote,
# with tests written against its own routes -- a closed loop that stayed green while the SDK
# emitted DER signatures no conformant peer could verify.
#
# Cloned rather than vendored, at the commit in .conformance-version. A pytest tree committed
# here would be picked up by phpstan, php-cs-fixer, composer-unused and the internal-class
# scanner, and would fork a suite whose whole value is that it moves independently of us.
# Upstream publishes no tags, so the pin is a commit SHA.
#
# Two constraints worth knowing before changing anything:
#
#   * The suite runs a mock agent-profile server and a mock webhook receiver on localhost, and
#     hardcodes `http://localhost:<port>` in the UCP-Agent header it sends. The merchant server
#     must therefore resolve the same localhost -- same host, or a shared network namespace.
#   * Its fixture paths (`--conformance_input`, `--fixture_config`) are absl flags, and its
#     conftest calls FLAGS(["pytest"]), which discards argv. Only SERVER_URL is settable from
#     the environment. So this script copies our fixtures over the defaults in the checkout.
#
set -euo pipefail

repo="$(cd "$(dirname "$0")/.." && pwd)"
cd "${repo}"

pinned="$(tr -d '[:space:]' < .conformance-version)"
checkout="${UCP_CONFORMANCE_DIR:-${repo}/var/conformance}"
venv="${UCP_CONFORMANCE_VENV:-${repo}/var/conformance-venv}"
server_url="${SERVER_URL:-http://127.0.0.1:8081}"
state_dir="${UCP_MERCHANT_STATE_DIR:-${repo}/var/conformance-state}"
report="${UCP_CONFORMANCE_REPORT:-${repo}/var/reports/conformance/junit.xml}"
server_log="$(dirname "${report}")/merchant.log"
python_bin="${PYTHON:-python3}"
# Shared by both sides. The suite defaults it to a fresh uuid, which the merchant cannot know,
# so the simulation endpoint would answer 403 to a correct-secret test.
SIMULATION_SECRET="${SIMULATION_SECRET:-ucp-conformance-simulation-secret}"
export SIMULATION_SECRET

mkdir -p "$(dirname "${report}")"

if [ ! -d "${checkout}/.git" ]; then
    rm -rf "${checkout}"
    git clone --quiet https://github.com/Universal-Commerce-Protocol/conformance.git "${checkout}"
fi
# Skippable so a local modification to the suite -- reproducing a defect, or testing a patch
# before sending it upstream -- is not silently reverted on the next run.
if [ -z "${UCP_CONFORMANCE_NO_CHECKOUT:-}" ]; then
    git -C "${checkout}" fetch --quiet origin
    git -C "${checkout}" checkout --quiet --force "${pinned}"
    # Hard reset, not just a checkout: `git checkout <sha>` keeps working-tree modifications, so
    # the patches below would be applied on top of themselves on a second local run -- and, worse,
    # a hand-edit made while debugging would silently persist into a run reported as pinned.
    git -C "${checkout}" reset --quiet --hard "${pinned}"
    git -C "${checkout}" clean --quiet -fd
fi
echo "conformance suite pinned at ${pinned}"

# The suite does not pass against any conformant merchant unpatched: its mock agent profile
# declares one capability while the tests exercise seven, so everything touching checkout is
# refused as `capabilities_incompatible` before it starts. docs/upstream/ carries the fixes and
# the reasoning; they are applied here so the lane is reproducible from a clean clone rather
# than depending on someone having applied them by hand.
#
# A patch that stops applying means upstream has changed that code -- possibly fixed it. That is
# a finding, so it fails loudly instead of running a suite in an unknown state.
if [ -z "${UCP_CONFORMANCE_NO_CHECKOUT:-}" ]; then
    for patch in "${repo}"/docs/upstream/*.patch; do
        [ -e "${patch}" ] || continue
        if ! git -C "${checkout}" apply "${patch}"; then
            echo "conformance: ${patch##*/} no longer applies. Upstream moved -- re-check docs/upstream/ before deleting it." >&2
            exit 1
        fi
    done
fi

if [ ! -x "${venv}/bin/python" ]; then
    "${python_bin}" -m venv "${venv}"
fi
"${venv}/bin/pip" install --quiet --disable-pip-version-check -e "${checkout}"

# The suite reads its fixtures from a path it will not let us configure; see the header.
cp tests/conformance/conformance_input.json "${checkout}/test_data/flower_shop/conformance_input.json"
cp tests/conformance/test_fixtures.json "${checkout}/test_data/flower_shop/test_fixtures.json"
# Payment instruments come from a CSV rather than the JSON fixtures, and the default names a
# handler this merchant does not implement -- which reads as 24 unrelated test failures.
cp tests/conformance/payment_instruments.csv "${checkout}/test_data/flower_shop/payment_instruments.csv"

server_pid=''
cleanup() {
    if [ -n "${server_pid}" ]; then
        kill "${server_pid}" 2>/dev/null || true
        wait "${server_pid}" 2>/dev/null || true
    fi
}
trap cleanup EXIT

if [ -z "${UCP_CONFORMANCE_SKIP_SERVER:-}" ]; then
    rm -rf "${state_dir}" "${repo}/examples/merchant-symfony-app/var/cache"
    mkdir -p "${state_dir}"

    # prod on purpose, with exactly one affordance turned on explicitly.
    #
    # The suite serves its mock agent profile at `http://localhost:<port>` and cannot serve
    # https, while the SDK refuses plain-http profile fetching outside development mode --
    # correctly, since a profile carries the keys that verify every request from that
    # platform. So the run cannot proceed without UCP_PROFILE_FETCHING_DEV_MODE, and it is
    # set here rather than inherited from APP_ENV=dev so that this one relaxation is the only
    # one in play and is visible in the command that needs it.
    #
    # This used to be acquired by accident: index.php read APP_ENV from $_SERVER and $_ENV
    # only, and under `php -S` an exported variable reaches neither, so `APP_ENV=prod`
    # resolved to `dev` and the whole run got the dev container. The trailing index.php is
    # the router script and is not optional -- without it the built-in server 404s every UCP
    # path before the application is reached.
    # A merchant with no signing key cannot sign a webhook, and the dispatcher refuses to send
    # one unsigned -- so without this the order events simply never leave, and the suite reports
    # it as the business failing to announce the order. The state directory is wiped per run, so
    # the key has to be provisioned per run too.
    APP_ENV=prod APP_DEBUG=0 \
        UCP_MERCHANT_BASE_URI="${server_url}" \
        UCP_MERCHANT_STATE_DIR="${state_dir}" \
        UCP_PROFILE_FETCHING_DEV_MODE=1 \
        php examples/merchant-symfony-app/bin/console ucp:signing-keys:generate >> "${server_log}" 2>&1

    APP_ENV=prod APP_DEBUG=0 \
        UCP_MERCHANT_BASE_URI="${server_url}" \
        UCP_MERCHANT_STATE_DIR="${state_dir}" \
        UCP_PROFILE_FETCHING_DEV_MODE=1 \
        SIMULATION_SECRET="${SIMULATION_SECRET}" \
        php -S "${server_url#http://}" \
            -t examples/merchant-symfony-app/public \
            examples/merchant-symfony-app/public/index.php \
            > "${server_log}" 2>&1 &
    server_pid=$!

    "${venv}/bin/python" - "${server_url}" <<'PY'
import sys, time, urllib.request, urllib.error
url = sys.argv[1].rstrip("/") + "/.well-known/ucp"
for _ in range(80):
    try:
        with urllib.request.urlopen(url, timeout=2) as response:
            if response.status == 200:
                print(f"merchant app answering at {url}")
                sys.exit(0)
    except (urllib.error.URLError, OSError):
        pass
    time.sleep(0.2)
sys.exit("merchant app never answered at " + url)
PY
fi

cd "${checkout}"
SERVER_URL="${server_url}" "${venv}/bin/python" -m pytest \
    ${UCP_CONFORMANCE_MODULES:-} \
    --junit-xml="${report}" \
    -q --no-header "$@"
