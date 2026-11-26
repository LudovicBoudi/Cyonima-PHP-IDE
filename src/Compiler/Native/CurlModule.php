<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Native;

/**
 * Module libcurl : handles opaques en `int`, corps et erreurs en chaînes.
 */
final class CurlModule implements NativeModule
{
    public function name(): string
    {
        return 'curl';
    }

    public function functions(): array
    {
        return [
            'curl_init' => new NativeFunction('curl_init', ['url' => 'string'], 'int'),
            'curl_set_method' => new NativeFunction(
                'curl_set_method',
                ['handle' => 'int', 'method' => 'string'],
                'bool',
            ),
            'curl_set_body' => new NativeFunction(
                'curl_set_body',
                ['handle' => 'int', 'body' => 'string'],
                'bool',
            ),
            'curl_set_header' => new NativeFunction(
                'curl_set_header',
                ['handle' => 'int', 'header' => 'string'],
                'bool',
            ),
            'curl_perform' => new NativeFunction('curl_perform', ['handle' => 'int'], 'bool'),
            'curl_status' => new NativeFunction('curl_status', ['handle' => 'int'], 'int'),
            'curl_body' => new NativeFunction('curl_body', ['handle' => 'int'], 'string'),
            'curl_error' => new NativeFunction('curl_error', ['handle' => 'int'], 'string'),
            'curl_close' => new NativeFunction('curl_close', ['handle' => 'int'], 'void'),
        ];
    }

    public function includes(): array
    {
        return ['#include <curl/curl.h>'];
    }

    public function source(): string
    {
        return <<<'C'
            typedef struct {
                CURL *curl;
                struct curl_slist *headers;
                char *body;
                size_t body_size;
                char *error;
                cy_int status;
                char *method;
                char *post_body;
            } CyCurlHandle;

            static char *cy_curl_strdup(const char *value)
            {
                size_t length = strlen(value) + 1;
                char *copy = (char *) malloc(length);

                if (copy != NULL) {
                    memcpy(copy, value, length);
                }

                return copy;
            }

            static CyCurlHandle *cy_curl_handle(cy_int handle)
            {
                return (CyCurlHandle *) (intptr_t) handle;
            }

            static size_t cy_curl_write(char *data, size_t size, size_t count, void *userdata)
            {
                CyCurlHandle *handle = (CyCurlHandle *) userdata;
                size_t length = size * count;
                char *next = (char *) realloc(handle->body, handle->body_size + length + 1);

                if (next == NULL) {
                    return 0;
                }

                handle->body = next;
                memcpy(handle->body + handle->body_size, data, length);
                handle->body_size += length;
                handle->body[handle->body_size] = '\0';

                return length;
            }

            static cy_int fn_curl_init(const char *url)
            {
                CyCurlHandle *handle = (CyCurlHandle *) calloc(1, sizeof(CyCurlHandle));

                if (handle == NULL) {
                    return 0;
                }

                handle->curl = curl_easy_init();

                if (handle->curl == NULL) {
                    free(handle);
                    return 0;
                }

                handle->error = cy_curl_strdup("");
                curl_easy_setopt(handle->curl, CURLOPT_URL, url);
                curl_easy_setopt(handle->curl, CURLOPT_FOLLOWLOCATION, 1L);
                curl_easy_setopt(handle->curl, CURLOPT_USERAGENT, "Cyonima/0.1");
                curl_easy_setopt(handle->curl, CURLOPT_WRITEFUNCTION, cy_curl_write);
                curl_easy_setopt(handle->curl, CURLOPT_WRITEDATA, handle);

                return (cy_int) (intptr_t) handle;
            }

            static bool fn_curl_set_method(cy_int raw, const char *method)
            {
                CyCurlHandle *handle = cy_curl_handle(raw);

                if (handle == NULL) {
                    return false;
                }

                free(handle->method);
                handle->method = cy_curl_strdup(method);

                return true;
            }

            static bool fn_curl_set_body(cy_int raw, const char *body)
            {
                CyCurlHandle *handle = cy_curl_handle(raw);

                if (handle == NULL) {
                    return false;
                }

                free(handle->post_body);
                handle->post_body = cy_curl_strdup(body);

                return true;
            }

            static bool fn_curl_set_header(cy_int raw, const char *header)
            {
                CyCurlHandle *handle = cy_curl_handle(raw);

                if (handle == NULL) {
                    return false;
                }

                struct curl_slist *next = curl_slist_append(handle->headers, header);

                if (next == NULL) {
                    return false;
                }

                handle->headers = next;

                return true;
            }

            static bool fn_curl_perform(cy_int raw)
            {
                CyCurlHandle *handle = cy_curl_handle(raw);

                if (handle == NULL || handle->curl == NULL) {
                    return false;
                }

                handle->body_size = 0;

                if (handle->body != NULL) {
                    handle->body[0] = '\0';
                }

                free(handle->error);
                handle->error = cy_curl_strdup("");
                handle->status = 0;

                if (handle->headers != NULL) {
                    curl_easy_setopt(handle->curl, CURLOPT_HTTPHEADER, handle->headers);
                }

                curl_easy_setopt(handle->curl, CURLOPT_POST, 0L);
                curl_easy_setopt(handle->curl, CURLOPT_CUSTOMREQUEST, NULL);
                curl_easy_setopt(handle->curl, CURLOPT_POSTFIELDS, NULL);

                if (handle->method != NULL && strcmp(handle->method, "POST") == 0) {
                    curl_easy_setopt(handle->curl, CURLOPT_POST, 1L);
                    curl_easy_setopt(
                        handle->curl,
                        CURLOPT_POSTFIELDS,
                        handle->post_body != NULL ? handle->post_body : ""
                    );
                } else if (handle->method != NULL && strcmp(handle->method, "GET") != 0) {
                    curl_easy_setopt(handle->curl, CURLOPT_CUSTOMREQUEST, handle->method);
                    curl_easy_setopt(
                        handle->curl,
                        CURLOPT_POSTFIELDS,
                        handle->post_body != NULL ? handle->post_body : ""
                    );
                } else {
                    curl_easy_setopt(handle->curl, CURLOPT_HTTPGET, 1L);
                }

                CURLcode code = curl_easy_perform(handle->curl);
                long status = 0;
                curl_easy_getinfo(handle->curl, CURLINFO_RESPONSE_CODE, &status);
                handle->status = (cy_int) status;

                if (code != CURLE_OK) {
                    free(handle->error);
                    handle->error = cy_curl_strdup(curl_easy_strerror(code));
                    return false;
                }

                return true;
            }

            static cy_int fn_curl_status(cy_int raw)
            {
                CyCurlHandle *handle = cy_curl_handle(raw);

                return handle == NULL ? 0 : handle->status;
            }

            static const char *fn_curl_body(cy_int raw)
            {
                CyCurlHandle *handle = cy_curl_handle(raw);

                if (handle == NULL || handle->body == NULL) {
                    return "";
                }

                return cy_strdup(handle->body);
            }

            static const char *fn_curl_error(cy_int raw)
            {
                CyCurlHandle *handle = cy_curl_handle(raw);

                if (handle == NULL || handle->error == NULL) {
                    return "";
                }

                return cy_strdup(handle->error);
            }

            static void fn_curl_close(cy_int raw)
            {
                CyCurlHandle *handle = cy_curl_handle(raw);

                if (handle == NULL) {
                    return;
                }

                if (handle->curl != NULL) {
                    curl_easy_cleanup(handle->curl);
                }

                if (handle->headers != NULL) {
                    curl_slist_free_all(handle->headers);
                }

                free(handle->body);
                free(handle->error);
                free(handle->method);
                free(handle->post_body);
                free(handle);
            }
            C;
    }

    public function pkgConfig(): ?string
    {
        return 'libcurl';
    }

    public function linkFlags(): string
    {
        return '-lcurl';
    }

    public function flatpakLibraries(): array
    {
        return [];
    }
}
