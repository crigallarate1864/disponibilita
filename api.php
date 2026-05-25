<?php
// api.php - Gestisce la pubblicazione, l'invio mail e la prenotazione
include 'db.php'; // Colleghiamo il database creato nel passo precedente

// Permettiamo al frontend di comunicare in formato JSON
header("Content-Type: application/json");

// Capiamo cosa vuole fare l'utente tramite il parametro "azione" nell'URL (es. api.php?azione=pubblica)
$azione = isset($_GET['azione']) ? $_GET['azione'] : '';

// -------------------------------------------------------------------------
// CASO 1: IL RESPONSABILE PUBBLICA UN NUOVO TURNO
// -------------------------------------------------------------------------
if ($azione == 'pubblica') {
    // Leggiamo i dati inviati dalla pagina del responsabile
    $data = json_decode(file_get_contents("php://input"), true);
    
    $data_turno = $data['data_turno'];
    $orario     = $data['orario'];     // es. "08:00 - 14:00"
    $tipologia  = $data['tipologia'];  // es. "MSB", "MSA"

    // 1. Inseriamo il turno nel database come 'disponibile'
    $stmt = $pdo->prepare("INSERT INTO turni (data_turno, orario, tipologia, stato) VALUES (?, ?, ?, 'disponibile')");
    $stmt->execute([$data_turno, $orario, $tipologia]);
    
    // Prendiamo l'ID del turno appena creato, ci serve per il link nella mail
    $id_turno_creato = $pdo->lastInsertId();

    // 2. Recuperiamo tutte le email degli operatori per avvisarli
    $stmt_utenti = $pdo->query("SELECT email FROM operatori");
    $operatori = $stmt_utenti->fetchAll(PDO::FETCH_ASSOC);

    // Se ci sono operatori nel database, prepariamo e inviamo la mail
    if (count($operatori) > 0) {
        // Creiamo una lista di destinatari separati da virgola
        $destinatari = [];
        foreach ($operatori as $op) {
            $destinatari[] = $op['email'];
        }
        $a = implode(',', $destinatari);

        // Oggetto della mail
        $oggetto = "⚠️ NUOVA COPERTURA TURNO 118 - $tipologia";

        // Cambia "il-tuo-dominio.it" con il tuo vero indirizzo Aruba quando caricherai i file
        $link_prenotazione = "https://www.il-tuo-dominio.it/index.html?turno_id=" . $id_turno_creato;

        // Corpo della mail (In formato HTML per renderla più leggibile)
        $messaggio = "
        <html>
        <head><title>Nuovo Turno Disponibile</title></head>
        <body>
            <h2>È richiesta una copertura urgente per il turno 118</h2>
            <p><strong>Tipologia:</strong> $tipologia</p>
            <p><strong>Data:</strong> $data_turno</p>
            <p><strong>Orario:</strong> $orario</p>
            <br>
            <p>Il sistema applica la regola del <em>'Primo che arriva ottiene il turno'</em>.</p>
            <p><a href='$link_prenotazione' style='background-color: #d9534f; color: white; padding: 10px 20px; text-decoration: none; font-weight: bold; border-radius: 5px;'>ENTRA E PRENDI IL TURNO</a></p>
        </body>
        </html>
        ";

        // Intestazioni per inviare una mail in formato HTML su Aruba
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: sistema-turni@il-tuo-dominio.it" . "\r\n"; // Mittente fittizio del tuo dominio

        // Invia la mail tramite il server Aruba
        mail($a, $oggetto, $messaggio, $headers);
    }

    echo json_encode(["status" => "success", "message" => "Turno pubblicato e mail inviate!"]);
    exit;
}

// -------------------------------------------------------------------------
// CASO 2: UN OPERATORE CLICCA PER PRENDERE IL TURNO (Blocco Concorrenza)
// -------------------------------------------------------------------------
if ($azione == 'prenota') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    $turno_id   = $data['turno_id'];
    $operatore_id = $data['operatore_id']; // Nelle prove simuleremo chi è l'operatore

    // Recuperiamo il nome dell'operatore per metterlo nel registro del turno
    $stmt_op = $pdo->prepare("SELECT nome FROM operatori WHERE id = ?");
    $stmt_op->execute([$operatore_id]);
    $operatore = $stmt_op->fetch(PDO::FETCH_ASSOC);
    $nome_operatore = $operatore['nome'];

    // QUERY CRITICA: Assegniamo il turno SOLO SE lo stato è ancora 'disponibile'.
    // Se un altro utente è arrivato 1 millisecondo prima, questa query modificherà 0 righe.
    $stmt = $pdo->prepare("UPDATE turni SET preso_da = ?, stato = 'preso', data_assegnazione = NOW() WHERE id = ? AND stato = 'disponibile'");
    $stmt->execute([$nome_operatore, $turno_id]);

    // Verifichiamo se la query ha modificato la riga (ovvero se il turno era effettivamente libero)
    if ($stmt->rowCount() > 0) {
        
        // AGGIORNAMENTO SCORE: Diamo ad esempio 10 punti all'operatore che ha coperto il turno
        $stmt_score = $pdo->prepare("UPDATE operatori SET score = score + 10 WHERE id = ?");
        $stmt_score->execute([$operatore_id]);

        echo json_encode(["status" => "success", "message" => "Complimenti! Turno assegnato a te. +10 Punti Score!"]);
    } else {
        // Se rowCount è 0, significa che qualcun altro ha cliccato prima
        echo json_encode(["status" => "error", "message" => "Spiacente, un altro operatore ha appena preso questo turno!"]);
    }
    exit;
}

// -------------------------------------------------------------------------
// CASO 3: VISUALIZZARE I TURNI DISPONIBILI NELLA PAGINA
// -------------------------------------------------------------------------
if ($azione == 'lista_turni') {
    $stmt = $pdo->query("SELECT * FROM turni ORDER BY data_turno ASC");
    $turni = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($turni);
    exit;
}
?>