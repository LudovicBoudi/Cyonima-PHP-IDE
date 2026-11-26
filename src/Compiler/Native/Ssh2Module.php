<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Native;

/**
 * Module libssh2 : session SSH, authentification mot de passe et exécution.
 */
final class Ssh2Module implements NativeModule
{
    public function name(): string
    {
        return 'ssh2';
    }

    public function functions(): array
    {
        return [
            'ssh2_connect' => new NativeFunction(
                'ssh2_connect',
                ['host' => 'string', 'port' => 'int'],
                'int',
            ),
            'ssh2_auth_password' => new NativeFunction(
                'ssh2_auth_password',
                ['handle' => 'int', 'user' => 'string', 'password' => 'string'],
                'bool',
            ),
            'ssh2_exec' => new NativeFunction(
                'ssh2_exec',
                ['handle' => 'int', 'command' => 'string'],
                'string',
            ),
            'ssh2_exit_status' => new NativeFunction(
                'ssh2_exit_status',
                ['handle' => 'int'],
                'int',
            ),
            'ssh2_error' => new NativeFunction('ssh2_error', ['handle' => 'int'], 'string'),
            'ssh2_disconnect' => new NativeFunction(
                'ssh2_disconnect',
                ['handle' => 'int'],
                'void',
            ),
        ];
    }

    public function includes(): array
    {
        return [
            '#include <libssh2.h>',
            '#include <netdb.h>',
            '#include <sys/socket.h>',
            '#include <sys/types.h>',
            '#include <unistd.h>',
        ];
    }

    public function source(): string
    {
        return <<<'C'
            typedef struct {
                int socket;
                LIBSSH2_SESSION *session;
                char error[512];
                cy_int exit_status;
            } CySshHandle;

            static bool cy_ssh_initialized = false;
            static char cy_ssh_last_error[512];

            static CySshHandle *cy_ssh_handle(cy_int raw)
            {
                return (CySshHandle *) (intptr_t) raw;
            }

            static void cy_ssh_set_error(CySshHandle *handle, const char *message)
            {
                if (handle == NULL) {
                    snprintf(cy_ssh_last_error, sizeof cy_ssh_last_error, "%s", message);
                    return;
                }

                snprintf(handle->error, sizeof handle->error, "%s", message);
            }

            static void cy_ssh_session_error(CySshHandle *handle, const char *fallback)
            {
                char *message = NULL;
                int length = 0;

                if (handle != NULL && handle->session != NULL) {
                    libssh2_session_last_error(handle->session, &message, &length, 0);
                }

                cy_ssh_set_error(
                    handle,
                    message != NULL && message[0] != '\0' ? message : fallback
                );
            }

            static int cy_ssh_connect_socket(const char *host, int port)
            {
                struct addrinfo hints;
                memset(&hints, 0, sizeof hints);
                hints.ai_family = AF_UNSPEC;
                hints.ai_socktype = SOCK_STREAM;

                char service[16];
                snprintf(service, sizeof service, "%d", port);

                struct addrinfo *results = NULL;

                if (getaddrinfo(host, service, &hints, &results) != 0) {
                    return -1;
                }

                int socket_fd = -1;

                for (struct addrinfo *current = results; current != NULL; current = current->ai_next) {
                    socket_fd = socket(current->ai_family, current->ai_socktype, current->ai_protocol);

                    if (socket_fd < 0) {
                        continue;
                    }

                    if (connect(socket_fd, current->ai_addr, current->ai_addrlen) == 0) {
                        break;
                    }

                    close(socket_fd);
                    socket_fd = -1;
                }

                freeaddrinfo(results);

                return socket_fd;
            }

            static cy_int fn_ssh2_connect(const char *host, cy_int port)
            {
                if (!cy_ssh_initialized) {
                    if (libssh2_init(0) != 0) {
                        cy_ssh_set_error(NULL, "initialisation libssh2 impossible");
                        return 0;
                    }

                    cy_ssh_initialized = true;
                }

                int socket_fd = cy_ssh_connect_socket(host, (int) port);

                if (socket_fd < 0) {
                    cy_ssh_set_error(NULL, "connexion TCP impossible");
                    return 0;
                }

                LIBSSH2_SESSION *session = libssh2_session_init();

                if (session == NULL) {
                    close(socket_fd);
                    cy_ssh_set_error(NULL, "session SSH impossible");
                    return 0;
                }

                libssh2_session_set_blocking(session, 1);

                if (libssh2_session_handshake(session, socket_fd) != 0) {
                    char *message = NULL;
                    int length = 0;
                    libssh2_session_last_error(session, &message, &length, 0);
                    cy_ssh_set_error(
                        NULL,
                        message != NULL && message[0] != '\0'
                            ? message
                            : "échange SSH impossible"
                    );
                    libssh2_session_free(session);
                    close(socket_fd);
                    return 0;
                }

                CySshHandle *handle = (CySshHandle *) calloc(1, sizeof(CySshHandle));

                if (handle == NULL) {
                    libssh2_session_disconnect(session, "mémoire insuffisante");
                    libssh2_session_free(session);
                    close(socket_fd);
                    cy_ssh_set_error(NULL, "mémoire insuffisante");
                    return 0;
                }

                handle->socket = socket_fd;
                handle->session = session;

                return (cy_int) (intptr_t) handle;
            }

            static bool fn_ssh2_auth_password(cy_int raw, const char *user, const char *password)
            {
                CySshHandle *handle = cy_ssh_handle(raw);

                if (handle == NULL || handle->session == NULL) {
                    return false;
                }

                if (libssh2_userauth_password(handle->session, user, password) != 0) {
                    cy_ssh_session_error(handle, "authentification refusée");
                    return false;
                }

                cy_ssh_set_error(handle, "");

                return true;
            }

            static const char *fn_ssh2_exec(cy_int raw, const char *command)
            {
                CySshHandle *handle = cy_ssh_handle(raw);

                if (handle == NULL || handle->session == NULL) {
                    return "";
                }

                LIBSSH2_CHANNEL *channel = libssh2_channel_open_session(handle->session);

                if (channel == NULL) {
                    cy_ssh_session_error(handle, "ouverture du canal impossible");
                    return "";
                }

                if (libssh2_channel_exec(channel, command) != 0) {
                    cy_ssh_session_error(handle, "exécution de la commande impossible");
                    libssh2_channel_free(channel);
                    return "";
                }

                char *output = NULL;
                size_t total = 0;
                char buffer[4096];

                for (;;) {
                    ssize_t count = libssh2_channel_read(channel, buffer, sizeof buffer);

                    if (count > 0) {
                        char *next = (char *) realloc(output, total + (size_t) count + 1);

                        if (next == NULL) {
                            free(output);
                            libssh2_channel_free(channel);
                            cy_ssh_set_error(handle, "mémoire insuffisante");
                            return "";
                        }

                        output = next;
                        memcpy(output + total, buffer, (size_t) count);
                        total += (size_t) count;
                        continue;
                    }

                    if (count == 0) {
                        break;
                    }

                    if (count == LIBSSH2_ERROR_EAGAIN) {
                        continue;
                    }

                    cy_ssh_session_error(handle, "lecture du canal impossible");
                    break;
                }

                handle->exit_status = (cy_int) libssh2_channel_get_exit_status(channel);
                libssh2_channel_free(channel);

                const char *result = "";

                if (output != NULL) {
                    output[total] = '\0';
                    result = cy_strdup(output);
                    free(output);
                }

                return result;
            }

            static cy_int fn_ssh2_exit_status(cy_int raw)
            {
                CySshHandle *handle = cy_ssh_handle(raw);

                return handle == NULL ? 0 : handle->exit_status;
            }

            static const char *fn_ssh2_error(cy_int raw)
            {
                CySshHandle *handle = cy_ssh_handle(raw);

                if (handle == NULL) {
                    return cy_strdup(cy_ssh_last_error);
                }

                return cy_strdup(handle->error);
            }

            static void fn_ssh2_disconnect(cy_int raw)
            {
                CySshHandle *handle = cy_ssh_handle(raw);

                if (handle == NULL) {
                    return;
                }

                if (handle->session != NULL) {
                    libssh2_session_disconnect(handle->session, "déconnexion");
                    libssh2_session_free(handle->session);
                }

                if (handle->socket >= 0) {
                    close(handle->socket);
                }

                free(handle);
            }
            C;
    }

    public function pkgConfig(): ?string
    {
        return 'libssh2';
    }

    public function linkFlags(): string
    {
        return '-lssh2';
    }

    public function flatpakLibraries(): array
    {
        return ['libssh2.so.1'];
    }
}
