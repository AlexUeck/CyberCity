<?php
include "../../includes/template.php";

// ---------------------------------------------------------
// Config: keep this in sync with the watcher
// ---------------------------------------------------------
$TIME_LIMIT_MINUTES = (int) (getenv('CYBER_DOCKER_TIME_LIMIT_MINUTES') ?: 10);

// ---------------------------------------------------------
// Auth & inputs
// ---------------------------------------------------------
if (!authorisedAccess(false, true, true)) {
    header("Location: ../../index.php");
    exit;
}

$challengeToLoad = isset($_GET["challengeID"]) ? (int) $_GET["challengeID"] : 0;
if ($challengeToLoad <= 0) {
    header("Location: ./challengesList.php");
    exit;
}

$userID = $_SESSION["user_id"] ?? null;
if (!$userID) {
    header("Location: ../../index.php");
    exit;
}

// ---------------------------------------------------------
// Fetch challenge
// ---------------------------------------------------------
$stmt = $conn->prepare("
    SELECT ID, challengeTitle, challengeText, pointsValue, flag, Image
    FROM Challenges
    WHERE ID = ?
");
$stmt->execute([$challengeToLoad]);
$challenge = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$challenge) {
    echo "<div class='alert alert-danger text-center mt-4'>Challenge not found.</div>";
    exit;
}

$challengeID   = (int)$challenge["ID"];
$title         = $challenge["challengeTitle"];
$challengeText = $challenge["challengeText"];
$pointsValue   = (int)$challenge["pointsValue"];
$flag          = $challenge["flag"];
$image         = $challenge["Image"];

// ---------------------------------------------------------
// Handle flag submission
// ---------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["hiddenflag"])) {
    $userFlag = sanitise_data($_POST["hiddenflag"]);

    if ($userFlag === $flag) {
        // Already solved?
        $check = $conn->prepare("SELECT 1 FROM UserChallenges WHERE userID = ? AND challengeID = ?");
        $check->execute([$userID, $challengeID]);
        if ($check->fetch()) {
            $_SESSION["flash_message"] = "<div class='bg-warning text-center p-2'>Flag Success! Challenge already completed, no points awarded.</div>";
            header("Location: ./challengesList.php");
            exit;
        }

        // Record solve + add points
        $ins = $conn->prepare("INSERT INTO UserChallenges (userID, challengeID) VALUES (?, ?)");
        $ins->execute([$userID, $challengeID]);

        $upd = $conn->prepare("UPDATE Users SET Score = Score + ? WHERE ID = ?");
        $upd->execute([$pointsValue, $userID]);

        $_SESSION["flash_message"] = "<div class='bg-success text-center p-2'>Success!</div>";
        header("Location: ./challengesList.php");
        exit;
    } else {
        $_SESSION["flash_message"] = "<div class='bg-danger text-center p-2'>Flag failed - try again</div>";
        // stay on page
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . '?challengeID=' . $challengeID);
        exit;
    }
}

// ---------------------------------------------------------
// Container state (per-user per-challenge)
// ---------------------------------------------------------
$containerStmt = $conn->prepare("
    SELECT timeInitialised, port
    FROM DockerContainers
    WHERE userID = ? AND challengeID = ?
    LIMIT 1
");
$containerStmt->execute([$userID, $challengeID]);
$container = $containerStmt->fetch(PDO::FETCH_ASSOC);

$ipAddress       = "10.177.202.196"; // TODO: make dynamic if needed
$timeInitialised = $container['timeInitialised'] ?? null;
$port            = $container['port'] ?? null;
$isRunning       = !empty($timeInitialised);

// Deletion time matches the watcher hard cap
$deletionTime = "Container not initialised";
if ($isRunning) {
    $t0 = strtotime($timeInitialised);
    if ($t0 !== false) {
        $deletionTime = date('G:i', $t0 + ($TIME_LIMIT_MINUTES * 60));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Challenge Info</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Axios -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <!-- (Bootstrap assumed available via your template include) -->
    <style>
        .flag-input { width: 100%; max-width: 420px; }
        .btn-wide   { min-width: 170px; }
    </style>
</head>
<body>
<header class="container-fluid d-flex align-items-center justify-content-center mt-3">
    <h1 class="text-uppercase">Challenge - <?= htmlspecialchars($title) ?></h1>
</header>

<section class="container my-4">
    <?php if (!empty($_SESSION["flash_message"])): ?>
        <div class="mt-2">
            <?= $_SESSION["flash_message"]; unset($_SESSION["flash_message"]); ?>
        </div>
    <?php endif; ?>

    <!-- Challenge details -->
    <div class="table-responsive my-4">
        <table class="table table-bordered table-hover text-center align-middle theme-table mb-0">
            <thead>
            <tr>
                <th style="width:15%">Image</th>
                <th style="width:20%">Title</th>
                <th style="width:50%">Description</th>
                <th style="width:10%">Points</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>
                    <?php if ($image): ?>
                        <img src="<?= BASE_URL ?>assets/img/challengeImages/<?= htmlspecialchars($image) ?>" alt="Challenge Image" width="100" height="100">
                    <?php else: ?>
                        <span class="text-muted">No Image</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($title) ?></td>
                <td class="text-start"><?= nl2br(htmlspecialchars($challengeText)) ?></td>
                <td class="fw-bold"><?= (int)$pointsValue ?></td>
            </tr>
            </tbody>
        </table>
    </div>

    <p class="text-success fw-bold text-center mt-3">Good luck and have fun!</p>
    <hr class="my-4 border-2 border-danger opacity-100">

    <!-- Flag Submission -->
    <form action="challengeDisplay.php?challengeID=<?= $challengeID ?>" method="post" class="mt-3">
        <div class="form-floating mb-3">
            <input type="text"
                   class="form-control flag-input"
                   id="flag"
                   name="hiddenflag"
                   placeholder="CTF{Flag_Here}">
            <p class="form-text text-start small">
                Press <b>Enter</b> when finished entering the flag.
            </p>
        </div>
    </form>

    <!-- Container controls -->
    <div class="table-responsive my-4">
        <table class="table table-bordered table-striped text-center align-middle theme-table mb-0">
            <thead>
            <tr>
                <th>Container Info</th>
                <th>Controls</th>
                <th>Shutdown Time</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td id="containerInfo">
                    <?=
                    $isRunning
                        ? "IP: " . htmlspecialchars($ipAddress) . "<br>Port: " . htmlspecialchars((string)$port)
                        : "Container not initialised"
                    ?>
                </td>
                <td>
                    <?php if ($isRunning): ?>
                        <button
                                id="toggleBtn"
                                class="btn btn-danger btn-wide"
                                data-state="running"
                                onclick="toggleContainer(<?= (int)$challengeID ?>, <?= (int)$userID ?>)">
                            Stop Container
                        </button>
                    <?php else: ?>
                        <button
                                id="toggleBtn"
                                class="btn btn-success btn-wide"
                                data-state="stopped"
                                onclick="toggleContainer(<?= (int)$challengeID ?>, <?= (int)$userID ?>)">
                            Start Container
                        </button>
                    <?php endif; ?>
                </td>
                <td id="shutdownCell">
                    <?= htmlspecialchars($deletionTime) ?>
                </td>
            </tr>
            </tbody>
        </table>
        <div class="small text-muted mt-2">
            Note: Containers automatically stop <?= (int)$TIME_LIMIT_MINUTES ?> minutes after start.
        </div>
    </div>
</section>

<script>
    // Disable/enable button + label
    function setBtnBusy(busy, label) {
        const btn = document.getElementById('toggleBtn');
        if (!btn) return;
        btn.disabled = !!busy;
        if (label) btn.textContent = label;
    }

    // Optimistic swap of button state
    function setBtnState(state) {
        const btn = document.getElementById('toggleBtn');
        if (!btn) return;
        btn.dataset.state = state;
        if (state === 'running') {
            btn.classList.remove('btn-success');
            btn.classList.add('btn-danger');
            btn.textContent = 'Stop Container';
        } else {
            btn.classList.remove('btn-danger');
            btn.classList.add('btn-success');
            btn.textContent = 'Start Container';
        }
    }

    <!-- Dark/Light Mode Table Toggling -->
    function applyTableTheme() {
        const body = document.body;
        const tables = document.querySelectorAll('.theme-table');

        tables.forEach(table => {
            table.classList.remove('table-dark', 'table-light');
            if (body.classList.contains('bg-dark')) {
                table.classList.add('table-dark');
            } else {
                table.classList.add('table-light');
            }
        });

        // Change text color by toggling classes on body
        // Assuming your theme toggle button toggles 'bg-dark' on body,
        // the CSS will handle text color automatically.
        // If you want to explicitly toggle text color classes, do it here:

        if (body.classList.contains('bg-dark')) {
            body.classList.add('text-light');
            body.classList.remove('text-dark');
        } else {
            body.classList.add('text-dark');
            body.classList.remove('text-light');
        }
    }

    // Initial call
    applyTableTheme();

    // Re-apply on toggle button click
    document.getElementById('modeToggle')?.addEventListener('click', () => {
        setTimeout(applyTableTheme, 50);
    });


function toggleContainer(challengeID, userID) {
        const btn = document.getElementById('toggleBtn');
        if (!btn) return;

        const currentState = btn.dataset.state; // 'running' | 'stopped'
        const isStarting = currentState === 'stopped';
        const url = isStarting
            ? '<?= BASE_URL ?>pages/challenges/docker/startContainer.php'
            : '<?= BASE_URL ?>pages/challenges/docker/stopContainer.php';

        // Prevent double-clicks + optimistic UI
        setBtnBusy(true, isStarting ? 'Starting…' : 'Stopping…');
        setBtnState(isStarting ? 'running' : 'stopped');

        axios.post(url, {
            challengeID: challengeID,
            userID: userID
        }).then(() => {
            // allow DB/binlog to settle, then sync UI
            setTimeout(() => location.reload(), 800);
        }).catch(err => {
            // revert on error
            setBtnState(currentState);
            setBtnBusy(false, currentState === 'stopped' ? 'Start Container' : 'Stop Container');
            console.error(err);
            alert('Action failed. Please try again.');
        });
    }
</script>
</body>
</html>
