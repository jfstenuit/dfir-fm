<?php
namespace Tests\Unit\Services\Oidc;

use Services\Oidc\HttpsClient;
use Services\Oidc\HttpsResponse;
use Services\Oidc\OidcException;

/** Answers by URL; an answer for a URL without its query also matches it with one */
final class StubHttpsClient implements HttpsClient
{
    /** @var array<string,HttpsResponse|\Closure> */
    public array $answers = [];
    /** @var list<string> */
    public array $requests = [];
    /** @var list<array{url:string,fields:array,basicAuth:?array,headers:array}> */
    public array $calls = [];

    public function get(string $url, array $headers = []): HttpsResponse
    {
        $this->requests[] = $url;
        $this->calls[] = ['url' => $url, 'fields' => [], 'basicAuth' => null, 'headers' => $headers];
        return $this->answer($url, []);
    }

    public function postForm(string $url, array $fields, ?array $basicAuth = null): HttpsResponse
    {
        $this->requests[] = $url;
        $this->calls[] = ['url' => $url, 'fields' => $fields, 'basicAuth' => $basicAuth, 'headers' => []];
        return $this->answer($url, $fields);
    }

    private function answer(string $url, array $fields): HttpsResponse
    {
        $answer = $this->answers[$url] ?? $this->answers[explode('?', $url)[0]] ?? throw new OidcException("unreachable: $url");
        return $answer instanceof \Closure ? $answer($url, $fields) : $answer;
    }
}
