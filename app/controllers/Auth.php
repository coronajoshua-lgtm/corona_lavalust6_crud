<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->database();
    }

    public function register()
    {
        $this->api->require_method('POST');
        $input = $this->request_body();
        $username = trim((string) ($input['username'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($username === '' || strlen($username) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 255 || strlen($password) < 8 || strlen($password) > 72) {
            $this->api->respond_error('Provide a username, valid email, and password between 8 and 72 characters.', 422);
        }

        $existing = $this->db->raw(
            'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$username, $email]
        )->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $this->api->respond_error('Username or email is already registered.', 409);
        }

        $this->db->raw(
            'INSERT INTO users (username, email, password, role, is_active) VALUES (?, ?, ?, ?, ?)',
            [$username, $email, password_hash($password, PASSWORD_DEFAULT), 'user', 1]
        );
        $userId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
        $tokens = $this->api->issue_tokens([
            'id' => $userId,
            'role' => 'user',
            'scopes' => ['read', 'write', 'delete'],
        ]);

        $this->api->respond([
            'message' => 'Account created.',
            'user' => ['id' => $userId, 'username' => $username, 'email' => $email],
            'tokens' => $tokens,
        ], 201);
    }

    public function login()
    {
        $this->api->require_method('POST');
        $input = $this->request_body();
        $identifier = trim((string) ($input['identifier'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $this->api->respond_error('Email or username and password are required.', 422);
        }

        $user = $this->db->raw(
            'SELECT id, username, email, password, role FROM users WHERE (email = ? OR username = ?) AND is_active = 1 LIMIT 1',
            [$identifier, $identifier]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            $this->api->respond_error('Invalid credentials.', 401);
        }

        $tokens = $this->api->issue_tokens([
            'id' => (int) $user['id'],
            'role' => $user['role'],
            'scopes' => ['read', 'write', 'delete'],
        ]);

        $this->api->respond([
            'message' => 'Signed in.',
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
            ],
            'tokens' => $tokens,
        ]);
    }

    public function refresh()
    {
        $this->api->require_method('POST');
        $input = $this->request_body();
        if (empty($input['refresh_token']) || !is_string($input['refresh_token'])) {
            $this->api->respond_error('Refresh token is required.', 422);
        }

        $this->api->refresh_access_token($input['refresh_token']);
    }

    public function logout()
    {
        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $input = $this->request_body();

        if (isset($input['refresh_token']) && is_string($input['refresh_token'])) {
            $refreshPayload = $this->api->validate_jwt($input['refresh_token'], 'refresh');
            if (!$refreshPayload || (string) $refreshPayload['sub'] !== (string) $payload['sub']) {
                $this->api->respond_error('Invalid refresh token.', 403);
            }
            $this->api->revoke_refresh_token($input['refresh_token']);
        }

        $this->api->respond(['message' => 'Signed out.']);
    }

    public function me()
    {
        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();
        $user = $this->db->raw(
            'SELECT id, username, email FROM users WHERE id = ? LIMIT 1',
            [$payload['sub']]
        )->fetch(PDO::FETCH_ASSOC);

        $this->api->respond(['user' => $user]);
    }

    private function request_body()
    {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $this->api->respond_error('Request body must be valid JSON.', 400);
        }

        return $body;
    }
}
