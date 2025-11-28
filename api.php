<?php
header('Content-Type: application/json; charset=utf-8');

$dbFile = __DIR__ . '/pacientes.db';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tabela principal
    $pdo->exec("CREATE TABLE IF NOT EXISTS pacientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        leito INTEGER NOT NULL,
        local TEXT NOT NULL,
        nascimento TEXT,
        atendimento TEXT,
        aguardando TEXT,
        entrada TEXT,
        pendencias TEXT,
        medico TEXT,
        conduta TEXT,
        dieta TEXT,
        hospital TEXT,
        observacao TEXT,
        aguardando_vaga TEXT,
        status TEXT DEFAULT 'ocupado'
    )");

    // Tabela
    $pdo->exec("CREATE TABLE IF NOT EXISTS auditoria (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT,
        leito INTEGER,
        local TEXT,
        entrada TEXT,
        saida TEXT,
        acao TEXT,
        hospital TEXT,
        timestamp TEXT
    )");

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? null;

//LISTAR
if ($method === 'GET' && $action === 'list') {
    $setores = [
        'Observação Masculina' => 5,
        'Observação Feminina' => 5,
        'Observação Pediátrica' => 5,
        'Isolamento Adulto' => 1,
        'Isolamento Pediátrico' => 1
    ];

    $stmt = $pdo->query("SELECT * FROM pacientes");
    $ocupados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado = [];

    foreach ($setores as $local => $qtd) {
        for ($i = 1; $i <= $qtd; $i++) {
            $leitoOcupado = null;
            foreach ($ocupados as $p) {
                if ($p['local'] === $local && intval($p['leito']) === $i) {
                    $leitoOcupado = $p;
                    break;
                }
            }

            if ($leitoOcupado) {
                $leitoOcupado['status'] = 'ocupado';
                $resultado[] = $leitoOcupado;
            } else {
                $resultado[] = [
                    'id' => null,
                    'nome' => '',
                    'leito' => $i,
                    'local' => $local,
                    'status' => 'livre'
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'data' => $resultado]);
    exit;
}

// Adicionar
if ($method === 'POST' && $action === 'add') {
    $nome = trim($input['nome'] ?? '');
    $leito = intval($input['leito'] ?? 0);
    $local = $input['local'] ?? '';

    if (!$nome || !$leito || !$local) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Preencha nome, leito e local']);
        exit;
    }

    date_default_timezone_set('America/Sao_Paulo');
    $entrada = (new DateTime())->format('Y-m-d H:i:s');

    // Inserir paciente
    $stmt = $pdo->prepare("INSERT INTO pacientes 
        (nome, leito, local, nascimento, atendimento, aguardando, entrada, pendencias, medico, conduta, dieta, hospital, observacao, aguardando_vaga, status)
        VALUES 
        (:nome, :leito, :local, :nascimento, :atendimento, :aguardando, :entrada, :pendencias, :medico, :conduta, :dieta, :hospital, :observacao, :aguardando_vaga, :status)");

    $stmt->execute([
        ':nome' => $nome,
        ':leito' => $leito,
        ':local' => $local,
        ':nascimento' => $input['nascimento'] ?? '',
        ':atendimento' => $input['atendimento'] ?? '',
        ':aguardando' => $input['aguardando'] ?? '',
        ':entrada' => $entrada,
        ':pendencias' => $input['pendencias'] ?? '',
        ':medico' => $input['medico'] ?? '',
        ':conduta' => $input['conduta'] ?? '',
        ':dieta' => $input['dieta'] ?? '',
        ':hospital' => $input['hospital'] ?? '',
        ':observacao' => $input['observacao'] ?? '',
        ':aguardando_vaga' => $input['aguardando_vaga'] ?? '',
        ':status' => 'ocupado'
    ]);

    // Registrar entrada na auditoria
    $stmtAudit = $pdo->prepare("
        INSERT INTO auditoria (nome, leito, local, entrada, acao, hospital, timestamp)
        VALUES (:nome, :leito, :local, :entrada, 'entrada', :hospital, :timestamp)
    ");
    $stmtAudit->execute([
        ':nome' => $nome,
        ':leito' => $leito,
        ':local' => $local,
        ':entrada' => $entrada,
        ':hospital' => $input['hospital'] ?? '',
        ':timestamp' => $entrada
    ]);

    echo json_encode(['success' => true, 'message' => 'Paciente adicionado e entrada registrada na auditoria']);
    exit;
}

//REMOVER
if ($method === 'POST' && $action === 'delete') {
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        exit;
    }

    $stmtInfo = $pdo->prepare("SELECT * FROM pacientes WHERE id = :id");
    $stmtInfo->execute([':id' => $id]);
    $p = $stmtInfo->fetch(PDO::FETCH_ASSOC);

    if ($p) {
        date_default_timezone_set('America/Sao_Paulo');
        $saida = (new DateTime())->format('Y-m-d H:i:s');

        $stmtAudit = $pdo->prepare("
            INSERT INTO auditoria (nome, leito, local, entrada, saida, acao, hospital, timestamp)
            VALUES (:nome, :leito, :local, :entrada, :saida, 'saida', :hospital, :timestamp)
        ");
        $stmtAudit->execute([
            ':nome' => $p['nome'],
            ':leito' => $p['leito'],
            ':local' => $p['local'],
            ':entrada' => $p['entrada'],
            ':saida' => $saida,
            ':hospital' => $p['hospital'] ?? '',
            ':timestamp' => $saida
        ]);
    }

    $stmt = $pdo->prepare("DELETE FROM pacientes WHERE id = :id");
    $stmt->execute([':id' => $id]);

    echo json_encode(['success' => true, 'message' => 'Paciente removido e saída registrada']);
    exit;
}

/////
if ($method === 'GET' && $action === 'auditoria') {
    $stmt = $pdo->query("SELECT * FROM auditoria ORDER BY timestamp DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}
///// Relatorio
if ($method === 'GET' && $action === 'relatorio') {
    header('Content-Type: application/json');

    $inicio = $_GET['inicio'] ?? '';
    $fim = $_GET['fim'] ?? '';
    $setor = $_GET['setor'] ?? '';
    $hospital = $_GET['hospital'] ?? '';
    $diaSemana = $_GET['diaSemana'] ?? '';

    $query = "SELECT * FROM auditoria WHERE 1=1";

    if ($inicio) $query .= " AND date(timestamp) >= date('$inicio')";
    if ($fim) $query .= " AND date(timestamp) <= date('$fim')";
    if ($setor) $query .= " AND local = '$setor'";
    if ($hospital) $query .= " AND hospital = '$hospital'";
    if ($diaSemana) $query .= " AND strftime('%w', timestamp) = '$diaSemana'";

    $query .= " ORDER BY nome, leito, timestamp";

    $stmt = $pdo->query($query);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dados = [];

    foreach ($rows as $r) {
        $chave = $r['nome'] . '-' . $r['leito'];

        if ($r['acao'] === 'entrada') {
            $dados[$chave] = [
                'nome' => $r['nome'],
                'local' => $r['local'],
                'leito' => $r['leito'],
                'entrada' => $r['entrada'],
                'saida' => '-',
                'hospital' => $r['hospital'] ?? '-'
            ];
        } elseif ($r['acao'] === 'saida') {
            if (isset($dados[$chave])) {
                $dados[$chave]['saida'] = $r['saida'];
            } else {
                $dados[$chave] = [
                    'nome' => $r['nome'],
                    'local' => $r['local'],
                    'leito' => $r['leito'],
                    'entrada' => '-',
                    'saida' => $r['saida'],
                    'hospital' => $r['hospital'] ?? '-'
                ];
            }
        }
    }

    $entradas = 0;
    $saidas = 0;
    $totalHoras = 0;
    $cont = 0;

    foreach ($dados as &$d) {
        if ($d['entrada'] !== '-') $entradas++;
        if ($d['saida'] !== '-') $saidas++;

        if ($d['entrada'] !== '-' && $d['saida'] !== '-') {
            $inicioDT = DateTime::createFromFormat('Y-m-d H:i:s', $d['entrada']) ?: DateTime::createFromFormat('d/m/Y H:i', $d['entrada']);
            $fimDT = DateTime::createFromFormat('Y-m-d H:i:s', $d['saida']) ?: DateTime::createFromFormat('d/m/Y H:i', $d['saida']);

            if ($inicioDT && $fimDT) {
                $diffHoras = round(($fimDT->getTimestamp() - $inicioDT->getTimestamp()) / 3600, 1);
                $d['tempo_ocupacao'] = $diffHoras . 'h';
                $totalHoras += $diffHoras;
                $cont++;
            } else {
                $d['tempo_ocupacao'] = '-';
            }
        } else {
            $d['tempo_ocupacao'] = '-';
        }
    }

    $media = $cont > 0 ? round($totalHoras / $cont, 1) . 'h' : '0h';

    echo json_encode([
        'success' => true,
        'data' => array_values($dados),
        'resumo' => [
            'entradas' => $entradas,
            'saidas' => $saidas,
            'media' => $media
        ]
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Requisição inválida']);
