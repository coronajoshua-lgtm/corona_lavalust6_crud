<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Products extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->database();
    }

    public function index()
    {
        $this->api->require_method('GET');
        $this->api->require_jwt();
        $products = $this->db->raw(
            'SELECT id, product_name, description, price, quantity, created_at FROM products ORDER BY created_at DESC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->api->respond(['data' => $products]);
    }

    public function show($id)
    {
        $this->api->require_method('GET');
        $this->api->require_jwt();
        $product = $this->find_product($id);

        if (!$product) {
            $this->api->respond_error('Product not found.', 404);
        }

        $this->api->respond(['data' => $product]);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $this->api->require_jwt();
        $product = $this->validate_product($this->request_body());

        $this->db->raw(
            'INSERT INTO products (product_name, description, price, quantity) VALUES (?, ?, ?, ?)',
            [$product['product_name'], $product['description'], $product['price'], $product['quantity']]
        );
        $id = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();

        $this->api->respond(['message' => 'Product created.', 'data' => $this->find_product($id)], 201);
    }

    public function update($id)
    {
        if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'], true)) {
            $this->api->respond_error('Method Not Allowed.', 405);
        }
        $this->api->require_jwt();

        $existing = $this->find_product($id);
        if (!$existing) {
            $this->api->respond_error('Product not found.', 404);
        }

        $input = $this->request_body();
        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            $input = array_merge([
                'product_name' => $existing['product_name'],
                'description' => $existing['description'],
                'price' => $existing['price'],
                'quantity' => $existing['quantity'],
            ], $input);
        }
        $product = $this->validate_product($input);
        $this->db->raw(
            'UPDATE products SET product_name = ?, description = ?, price = ?, quantity = ? WHERE id = ?',
            [$product['product_name'], $product['description'], $product['price'], $product['quantity'], $id]
        );

        $this->api->respond(['message' => 'Product updated.', 'data' => $this->find_product($id)]);
    }

    public function delete($id)
    {
        $this->api->require_method('DELETE');
        $this->api->require_jwt();
        if (!$this->find_product($id)) {
            $this->api->respond_error('Product not found.', 404);
        }

        $this->db->raw('DELETE FROM products WHERE id = ?', [$id]);
        $this->api->respond(['message' => 'Product deleted.']);
    }

    private function find_product($id)
    {
        return $this->db->raw(
            'SELECT id, product_name, description, price, quantity, created_at FROM products WHERE id = ? LIMIT 1',
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function validate_product($input)
    {
        $name = trim((string) ($input['product_name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $price = (string) ($input['price'] ?? '');
        $quantity = $input['quantity'] ?? null;

        if ($name === '' || strlen($name) > 100) {
            $this->api->respond_error('Product name is required and must be at most 100 characters.', 422);
        }
        if (strlen($description) > 65535) {
            $this->api->respond_error('Description is too long.', 422);
        }
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price)) {
            $this->api->respond_error('Price must be a non-negative amount with up to two decimal places.', 422);
        }
        if (!is_int($quantity) && !(is_string($quantity) && ctype_digit($quantity))) {
            $this->api->respond_error('Quantity must be a non-negative whole number.', 422);
        }
        if ((int) $quantity > 2147483647) {
            $this->api->respond_error('Quantity is too large.', 422);
        }

        return [
            'product_name' => $name,
            'description' => $description === '' ? null : $description,
            'price' => number_format((float) $price, 2, '.', ''),
            'quantity' => (int) $quantity,
        ];
    }

    private function request_body()
    {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $this->api->respond_error('Request body must be valid JSON.', 400);
        }

        return $body;
    }
}
