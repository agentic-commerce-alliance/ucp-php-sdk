<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Http\HttpResponse;
use Ucp\Sdk\Model\Security\ManagedSigningKey;

interface ResponseSignatureServiceInterface
{
    /**
     * Signs a response, binding it to the request that produced it.
     *
     * The request is not optional. A signature over a response alone says "a business said
     * this", not "a business said this to you, about that" -- so an intact response replayed
     * against a different request still verifies. RFC 9421 answers that with request-bound
     * components, and this signs them.
     *
     * @return array<string, string> headers to add to the response
     */
    public function sign(
        HttpResponse $response,
        HttpRequest $request,
        ManagedSigningKey $key,
        ?int $created = null,
        ?int $expires = null,
    ): array;
}
