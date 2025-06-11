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

$app->put('/juros', function (Request $request, Response $response) {
    $data = $request->getParsedBody();

    if (!isset($data['dataInicio']) || !isset($data['dataFinal'])) {
        return $response->withStatus(400); // Bad Request
    }

    try {
        $dataInicio = new DateTime($data['dataInicio']);
        $dataFinal = new DateTime($data['dataFinal']);
        $dataMinima = new DateTime('2010-01-01');
        $dataAtual = new DateTime();
    } catch (Exception $e) {
        return $response->withStatus(400); // Bad Request - Formato de data inválido
    }

    if (
        $dataInicio > $dataFinal ||
        $dataInicio < $dataMinima ||
        $dataFinal > $dataAtual
    ) {
        return $response->withStatus(400); // Bad Request - Violação das regras de data
    }

    $dataInicioFormatada = $dataInicio->format('d/m/Y');
    $dataFinalFormatada = $dataFinal->format('d/m/Y');
    
    $url = "https://api.bcb.gov.br/dados/serie/bcdata.sgs.11/dados?formato=json&dataInicial={$dataInicioFormatada}&dataFinal={$dataFinalFormatada}";

    set_error_handler(function($errno, $errstr) {
        throw new Exception($errstr, $errno);
    }, E_WARNING);

    try {
        $apiResponse = file_get_contents($url);
    } catch (Exception $e) {
        return $response->withStatus(400); // Bad Request
    } finally {
        restore_error_handler();
    }

    if ($apiResponse === false) {
        return $response->withStatus(400); // Bad Request
    }

    $selicDiaria = json_decode($apiResponse, true);

    $taxaSelicAcumulada = 0.0;
    if (is_array($selicDiaria)) {
        foreach ($selicDiaria as $registro) {
            $taxaSelicAcumulada += (float)$registro['valor'];
        }
    }
    
    $taxaSelicAcumulada = round($taxaSelicAcumulada, 2);

    $pdo = Database::getInstance();
    $sql = "UPDATE juros SET taxa_selic = :taxa, data_inicio = :data_inicio, data_final = :data_final ORDER BY id LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':taxa' => $taxaSelicAcumulada,
        ':data_inicio' => $data['dataInicio'],
        ':data_final' => $data['dataFinal']
    ]);

    $payload = json_encode(['novaTaxaJuros' => $taxaSelicAcumulada]);
    $response->getBody()->write($payload);

    return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200); // OK
});

$app->get('/compras', function (Request $request, Response $response) {
    $sql = "SELECT 
                c.id AS idCompra,
                p.nome AS nomeProduto,
                p.tipo AS tipoProduto,
                p.valor AS valorProduto,
                c.valor_entrada AS valorEntrada,
                c.qtd_parcelas AS qtdParcelas,
                c.valor_parcela AS valorParcela,
                c.taxa_juros AS taxaJuros
            FROM 
                compras c
            JOIN 
                produtos p ON c.id_produto = p.id
            ORDER BY 
                c.data_compra DESC";

    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->query($sql);
        $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($compras)) {
            return $response->withStatus(404); // Not Found
        }

        $payload = json_encode($compras);
        $response->getBody()->write($payload);

        return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200); // OK

    } catch (PDOException $e) {
        return $response->withStatus(500); // Internal Server Error
    }
});

$app->get('/estatistica', function (Request $request, Response $response) {
    $sql = "SELECT
                COUNT(c.id) AS `count`,
                SUM(c.valor_entrada + (c.qtd_parcelas * c.valor_parcela)) AS `sum`,
                SUM((c.valor_entrada + (c.qtd_parcelas * c.valor_parcela)) - p.valor) AS `sumTx`
            FROM
                compras c
            JOIN
                produtos p ON c.id_produto = p.id";

    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $stats = [
            'count' => 0,
            'sum' => 0.0,
            'avg' => 0.0,
            'sumTx' => 0.0,
            'avgTx' => 0.0
        ];
        
        if ($result && (int)$result['count'] > 0) {
            $count = (int)$result['count'];
            $sum = (float)($result['sum'] ?? 0.0);
            $sumTx = (float)($result['sumTx'] ?? 0.0);

            $stats['count'] = $count;
            $stats['sum'] = round($sum, 2);
            $stats['avg'] = round($sum / $count, 2);
            $stats['sumTx'] = round($sumTx, 2);
            $stats['avgTx'] = round($sumTx / $count, 2);
        }

        $payload = json_encode($stats);
        $response->getBody()->write($payload);

        return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200); // OK

    } catch (PDOException $e) {
        return $response->withStatus(500); // Internal Server Error
    }
});
$app->run();