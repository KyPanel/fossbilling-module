<?php

declare(strict_types=1);

// KyPanel server manager for FOSSBilling
// Server config: hostname, API token in the Password field, port 7711.
class Server_Manager_Kypanel extends Server_Manager
{
    private array $ids = [];

    public static function getForm(): array
    {
        return [
            'label' => 'KyPanel',
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'password',
                            'type' => 'text',
                            'label' => 'API token',
                            'placeholder' => 'Ky Admin › Development › Manage API Tokens ("accounts" read and change, "packages" read)',
                            'required' => true,
                            'secret' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['password'];
    }

    public function init(): void
    {
        if (trim((string) ($this->_config['host'] ?? '')) === '') {
            throw new Server_Exception('The KyPanel server hostname is missing. Please configure it in the server settings.');
        }
    }

    public function getPort(): int
    {
        $port = (int) ($this->_config['port'] ?? 0);

        return $port > 0 && $port < 65536 ? $port : 7711;
    }

    // null account = plain panel address; with an account it's a one-time sign-in link
    public function getLoginUrl(?Server_Account $account = null): string
    {
        if (!$account instanceof Server_Account) {
            return 'https://' . $this->_config['host'] . ':' . $this->getPort() . '/user/';
        }

        return $this->sso($account, 'user');
    }

    public function getResellerLoginUrl(?Server_Account $account = null): string
    {
        if (!$account instanceof Server_Account) {
            return 'https://' . $this->_config['host'] . ':' . $this->getPort() . '/admin/';
        }

        return $this->sso($account, 'admin');
    }

    // KyPanel usernames: a letter, then letters or digits, 16 max
    #[Override]
    public function generateUsername(string $domain): string
    {
        $base = ltrim((string) preg_replace('/[^a-z0-9]/', '', strtolower($domain)), '0123456789');
        $prefix = strtolower((string) ($this->_config['config']['userprefix'] ?? ''));
        $username = substr($prefix . ($base !== '' ? $base : 'user'), 0, 7) . random_int(0, 9);
        if (!ctype_alpha($username[0])) {
            $username = 'a' . substr($username, 1);
        }

        return $username;
    }

    public function testConnection(): bool
    {
        $this->request('GET', '/api/v1/packages');
        $this->request('GET', '/api/v1/accounts', null, ['page' => 1, 'per_page' => 1]);

        return true;
    }

    public function synchronizeAccount(Server_Account $account): Server_Account
    {
        $a = $this->request('GET', '/api/v1/accounts/' . rawurlencode($this->accountId($account->getUsername())));
        $new = clone $account;
        $new->setSuspended(($a['status'] ?? '') === 'suspended');

        return $new;
    }

    public function createAccount(Server_Account $account): bool
    {
        $client = $account->getClient();
        $body = [
            'username' => strtolower((string) $account->getUsername()),
            'domain' => strtolower((string) $account->getDomain()),
            'password' => (string) $account->getPassword(),
            'contact_email' => $client instanceof Server_Client ? (string) $client->getEmail() : '',
            'package_id' => $this->packageId((string) $account->getPackage()->getName()),
        ];
        if ($account->getReseller()) {
            $body['make_reseller'] = true;
        }
        $res = $this->request('POST', '/api/v1/accounts', $body);
        $this->wait((string) ($res['job_id'] ?? ''));

        return true;
    }

    public function suspendAccount(Server_Account $account): bool
    {
        $res = $this->request('POST', $this->accountPath($account) . '/suspend', ['reason' => 'Suspended by billing']);
        $this->wait((string) ($res['job_id'] ?? ''));

        return true;
    }

    public function unsuspendAccount(Server_Account $account): bool
    {
        $res = $this->request('POST', $this->accountPath($account) . '/unsuspend', new stdClass());
        $this->wait((string) ($res['job_id'] ?? ''));

        return true;
    }

    public function cancelAccount(Server_Account $account): bool
    {
        $username = strtolower((string) $account->getUsername());
        $res = $this->request('DELETE', $this->accountPath($account), null, ['confirm' => $username]);
        $this->wait((string) ($res['job_id'] ?? ''));

        return true;
    }

    public function changeAccountPackage(Server_Account $account, Server_Package $package): bool
    {
        $res = $this->request('PUT', $this->accountPath($account) . '/package', [
            'package_id' => $this->packageId((string) $package->getName()),
        ]);
        $this->wait((string) ($res['job_id'] ?? ''));

        return true;
    }

    public function changeAccountPassword(Server_Account $account, string $newPassword): bool
    {
        $this->request('POST', $this->accountPath($account) . '/password', ['new_password' => $newPassword]);

        return true;
    }

    public function changeAccountUsername(Server_Account $account, string $newUsername): never
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => 'KyPanel', ':action:' => __trans('username changes')]);
    }

    public function changeAccountDomain(Server_Account $account, string $newDomain): never
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => 'KyPanel', ':action:' => __trans('changing the account domain')]);
    }

    public function changeAccountIp(Server_Account $account, string $newIp): never
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => 'KyPanel', ':action:' => __trans('changing the account IP')]);
    }

    private function sso(Server_Account $account, string $panel): string
    {
        $res = $this->request('POST', $this->accountPath($account) . '/sso', ['panel' => $panel]);

        return (string) ($res['url'] ?? '');
    }

    private function accountPath(Server_Account $account): string
    {
        return '/api/v1/accounts/' . rawurlencode($this->accountId((string) $account->getUsername()));
    }

    private function accountId(string $username): string
    {
        $username = strtolower(trim($username));
        if ($username === '') {
            throw new Server_Exception('The service has no username.');
        }
        if (isset($this->ids[$username])) {
            return $this->ids[$username];
        }
        $page = $this->request('GET', '/api/v1/accounts', null, ['q' => $username, 'page' => 1, 'per_page' => 100]);
        foreach ($page['items'] ?? [] as $a) {
            if (strtolower((string) ($a['username'] ?? '')) === $username) {
                return $this->ids[$username] = (string) $a['id'];
            }
        }
        throw new Server_Exception('There is no account named ":user:" on this KyPanel server.', [':user:' => $username]);
    }

    // accepts the package name or id
    private function packageId(string $nameOrId): string
    {
        $want = trim($nameOrId);
        $names = [];
        foreach ($this->request('GET', '/api/v1/packages')['items'] ?? [] as $p) {
            if ($p['id'] === $want || strcasecmp((string) $p['name'], $want) === 0) {
                return (string) $p['id'];
            }
            $names[] = $p['name'];
        }
        throw new Server_Exception('There is no package ":package:" on this KyPanel server. Packages: :list:.', [
            ':package:' => $want,
            ':list:' => $names ? implode(', ', $names) : 'none yet',
        ]);
    }

    // create/suspend/terminate run as jobs on the server, poll until done
    private function wait(string $jobId, int $seconds = 240): void
    {
        if ($jobId === '') {
            return;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds + 60);
        }
        $until = time() + $seconds;
        $delay = 1;
        do {
            $job = $this->request('GET', '/api/v1/jobs/' . rawurlencode($jobId))['job'] ?? [];
            $status = $job['status'] ?? '';
            if ($status === 'succeeded') {
                return;
            }
            if (in_array($status, ['failed', 'canceled', 'cancelled'], true)) {
                throw new Server_Exception((string) ($job['error'] ?? 'The KyPanel job failed.'));
            }
            sleep($delay);
            $delay = min($delay + 1, 5);
        } while (time() < $until);
        throw new Server_Exception('KyPanel is still working on it (job :job:). Check Ky Admin before trying again.', [':job:' => $jobId]);
    }

    private function request(string $method, string $path, $body = null, array $query = []): array
    {
        $token = trim((string) ($this->_config['password'] ?? ''));
        if ($token === '') {
            throw new Server_Exception('Paste a KyPanel API token in the server\'s Password field.');
        }
        $url = 'https://' . $this->_config['host'] . ':' . $this->getPort() . $path . ($query ? '?' . http_build_query($query) : '');
        // "Use SSL" off = accept a self-signed certificate (a new server before AutoSSL)
        $verify = (bool) ($this->_config['secure'] ?? false);

        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json', 'User-Agent: KyPanel-FOSSBilling'];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $this->getLog()->debug('KyPanel ' . $method . ' ' . $path . ' -> ' . $code);

        if ($raw === false) {
            throw new Server_Exception('Couldn\'t reach the KyPanel server: :error:', [':error:' => $err]);
        }
        $data = $raw === '' ? [] : json_decode((string) $raw, true);
        if ($code >= 200 && $code < 300) {
            return is_array($data) ? $data : [];
        }
        $p = is_array($data) ? $data : [];
        $msg = (string) ($p['title'] ?? 'HTTP ' . $code);
        if (!empty($p['detail'])) {
            $msg .= ': ' . $p['detail'];
        }
        if (!empty($p['fields']) && is_array($p['fields'])) {
            $parts = [];
            foreach ($p['fields'] as $field => $text) {
                $parts[] = $field . ': ' . $text;
            }
            $msg .= ' (' . implode('; ', $parts) . ')';
        }
        throw new Server_Exception($msg);
    }
}
