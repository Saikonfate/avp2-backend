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

$app->post('/compras', function (Request $request, Response $response) {
    $data = $request->getParsedBody();

    if (
        !isset($data['id'], $data['valorEntrada'], $data['qtdParcelas'], $data['idProduto']) ||
        !is_string($data['id']) ||
        !is_numeric($data['valorEntrada']) ||
        !is_int($data['qtdParcelas']) ||
        !is_string($data['idProduto'])
    ) {
        return $response->withStatus(400); // Bad Request
    }

    $uuidPattern = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
    if (!preg_match($uuidPattern, $data['id']) || !preg_match($uuidPattern, $data['idProduto'])) {
        return $response->withStatus(422); // Unprocessable Entity - UUID inválido
    }

    if ($data['qtdParcelas'] <= 0 || $data['valorEntrada'] < 0) {
        return $response->withStatus(422); // Unprocessable Entity
    }

    $pdo = Database::getInstance();
    
    try {
        $pdo->beginTransaction();

        $stmtProduto = $pdo->prepare("SELECT valor FROM produtos WHERE id = :idProduto");
        $stmtProduto->execute([':idProduto' => $data['idProduto']]);
        $produto = $stmtProduto->fetch();

        if (!$produto) {
            $pdo->rollBack();
            return $response->withStatus(422); // Unprocessable Entity - Produto não encontrado
        }

        // Validação: a entrada não pode ser maior que o valor do produto
        if ($data['valorEntrada'] > $produto['valor']) {
            $pdo->rollBack();
            return $response->withStatus(422); // Unprocessable Entity - Entrada maior que o valor do produto
        }

        $stmtJuros = $pdo->query("SELECT taxa_selic FROM juros ORDER BY id DESC LIMIT 1");
        $juros = $stmtJuros->fetch();
        $taxaSelic = $juros ? (float)$juros['taxa_selic'] : 0.0;

        $taxaJurosAplicada = 0;
        if ($data['qtdParcelas'] > 6) { 
            $taxaJurosAplicada = $taxaSelic;
        }

        $valorFinanciado = $produto['valor'] - $data['valorEntrada'];
        $valorTotalComJuros = $valorFinanciado * (1 + ($taxaJurosAplicada / 100));
        $valorParcela = $valorTotalComJuros / $data['qtdParcelas'];

        $sql = "INSERT INTO compras (id, id_produto, valor_entrada, qtd_parcelas, valor_parcela, taxa_juros) 
                VALUES (:id, :id_produto, :valor_entrada, :qtd_parcelas, :valor_parcela, :taxa_juros)";
        
        $stmtCompra = $pdo->prepare($sql);
        $stmtCompra->execute([
            ':id' => $data['id'],
            ':id_produto' => $data['idProduto'],
            ':valor_entrada' => $data['valorEntrada'],
            ':qtd_parcelas' => $data['qtdParcelas'],
            ':valor_parcela' => round($valorParcela, 2),
            ':taxa_juros' => $taxaJurosAplicada
        ]);

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();

        if ($e->getCode() === '23000') {
            return $response->withStatus(422);
        }

        return $response->withStatus(422);
    }

    return $response->withStatus(201);
});
$app->run();