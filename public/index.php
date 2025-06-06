<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use App\Database;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->post('/produtos', function (Request $request, Response $response) {
    $data = $request->getParsedBody();

    if (
        !isset($data['id']) || !isset($data['nome']) || !isset($data['valor']) ||
        !is_string($data['id']) || !is_string($data['nome']) || !is_numeric($data['valor'])
    ) {
        return $response->withStatus(400);
    }

    $uuidPattern = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
    if (!preg_match($uuidPattern, $data['id'])) {
        return $response->withStatus(422); 
    }
    if ($data['valor'] < 0) {
        return $response->withStatus(422);
    }

    $sql = "INSERT INTO produtos (id, nome, tipo, valor) VALUES (:id, :nome, :tipo, :valor)";

    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':id' => $data['id'],
            ':nome' => $data['nome'],
            ':tipo' => $data['tipo'] ?? null, 
            ':valor' => $data['valor']
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return $response->withStatus(422); 
        }
        return $response->withStatus(422);
    }
    return $response->withStatus(201);
});

$app->run();