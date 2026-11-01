#!/bin/bash

# ⚙️  KONFIGURATION (hier anpassen)
SERVER="mmrtk@mmrtk.lima-ssh.de"
SSH_KEY="$HOME/.ssh/id_ed25519"    # Pfad zu deinem privaten SSH-Key
TARGET_DIR="sinclear.de/api"   # Zielverzeichnis auf dem Server

# Arcade-Spiele werden lokal gebaut (der Server hat kein Go) und nach dem
# git pull per scp in das jeweilige Spielverzeichnis hochgeladen.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARCADE_BUILD_SCRIPT="$SCRIPT_DIR/arcade-src/rocket-boost/build.sh"
ARCADE_OUT_DIR="$SCRIPT_DIR/public/arcade/rocket-boost"
ARCADE_UPLOAD_FILES=("rocket.wasm" "wasm_exec.js")

# 🎨 Farben
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# 📋 Hilfsfunktionen für die Ausgabe
print_header() {
    echo -e "${BOLD}${BLUE}========================================${NC}"
    echo -e "${BOLD}${BLUE}     🚀 Server Deployment Skript         ${NC}"
    echo -e "${BOLD}${BLUE}========================================${NC}"
    echo ""
}

print_step() {
    echo -e "${BLUE}${BOLD}→${NC} ${BLUE}$1${NC}"
}

print_info() {
    echo -e "${BOLD}ℹ️${NC} $1"
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

handle_error() {
    print_error "$1"
    exit 1
}

# 🖥️  Header anzeigen
print_header

# 1️⃣  Schritt 1: SSH-Verbindung testen (mit LogLevel=QUIET, um Willkommensnachricht zu unterdrücken)
print_step "1/7: Verbinde mit Server ($SERVER)..."
# Kein BatchMode: Ein passwortgeschützter Key soll nach der Passphrase fragen dürfen.
if ! ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no -o LogLevel=QUIET -o ConnectTimeout=15 "$SERVER" "echo SSH_OK" >/dev/null; then
    handle_error "SSH-Verbindung fehlgeschlagen! Überprüfe Server, Benutzer und SSH-Key."
fi
print_success "SSH-Verbindung erfolgreich"

# 2️⃣  Arcade-WASM lokal bauen (Go wird nur auf dem Entwicklungsrechner benötigt)
print_step "2/7: Baue Arcade-WASM lokal..."
if [ ! -x "$ARCADE_BUILD_SCRIPT" ]; then
    handle_error "Arcade-Build-Skript nicht gefunden: $ARCADE_BUILD_SCRIPT"
fi
if ! "$ARCADE_BUILD_SCRIPT"; then
    handle_error "Arcade-Build fehlgeschlagen (Go >= 1.24 installiert?)"
fi
print_success "Arcade-WASM gebaut"

# Der Server zieht main; lokale, uncommittete Arcade-Quellen würden ein WASM
# erzeugen, das nicht zum deployten Commit passt.
if command -v git >/dev/null 2>&1 && git -C "$SCRIPT_DIR" rev-parse --git-dir >/dev/null 2>&1; then
    if [ -n "$(git -C "$SCRIPT_DIR" status --porcelain -- arcade-src/rocket-boost)" ]; then
        print_warning "Arcade-Quellen haben uncommittete Änderungen – WASM passt evtl. nicht zum deployten Commit."
    fi
fi

# 3️⃣  Alle Befehle in EINER SSH-Sitzung ausführen (vermeidet wiederholte Willkommensnachrichten)
print_step "3/7: Prüfe Branch (main) und starte git pull..."
print_step "4/7: Führe composer install (mit Dev-Dependencies) aus..."
print_step "5/7: Führe PHPUnit-Tests aus..."
print_step "6/7: Führe composer install --no-dev aus (Bereinigung)..."
print_step "7/7: Lade Arcade-Build auf den Server..."
print_info "Hinweis: Ist der SSH-Key auf dem Server passwortgeschützt, wirst du hier nach der Passphrase gefragt."

# TTY erzwingen (-t), damit Passphrase-Abfragen (z. B. beim 'git pull' auf dem Server)
# im Terminal sichtbar sind und das Skript auf die Eingabe wartet. Die Ausgabe wird
# gleichzeitig live angezeigt und in eine Logdatei geschrieben, um sie danach auszuwerten.
DEPLOY_LOG=$(mktemp)
ssh -t -i "$SSH_KEY" -o StrictHostKeyChecking=no -o LogLevel=QUIET "$SERVER" "
    # Wechsle in das Verzeichnis
    cd $TARGET_DIR || { echo 'ERROR: Verzeichniswechsel fehlgeschlagen'; exit 1; }

    # Sicherstellen, dass main-Branch verwendet wird
    echo '---BRANCH_CHECK---'
    CURRENT_BRANCH=\$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
    if [ \"\$CURRENT_BRANCH\" != \"main\" ]; then
        echo \"WARN: Aktueller Branch ist '\$CURRENT_BRANCH' – Wechsle zu main...\"
        git -c color.ui=never checkout main 2>&1
        if [ \$? -ne 0 ]; then
            echo 'ERROR: Branch-Wechsel zu main fehlgeschlagen'
            exit 1
        fi
    fi
    echo '---BRANCH_OK---'

    # git pull (GIT_TERMINAL_PROMPT=1 stellt sicher, dass nach der Passphrase gefragt werden darf)
    echo '---GIT_START---'
    GIT_TERMINAL_PROMPT=1 git -c color.ui=never pull home main 2>&1
    GIT_EXIT=\$?
    echo '---GIT_END---'
    if [ \$GIT_EXIT -ne 0 ]; then
        echo 'ERROR: git pull fehlgeschlagen'
        exit 1
    fi

    # Deployten Commit auslesen (ID + Commit-Message)
    echo '---COMMIT_START---'
    git log -1 --format='%h|%s'
    echo '---COMMIT_END---'

    # composer install mit Dev-Dependencies (für Tests)
    echo '---COMPOSER_START---'
    composer dump-autoload --no-ansi
    composer install --no-interaction --no-ansi --prefer-dist --optimize-autoloader 2>&1
    COMPOSER_EXIT=\$?
    echo '---COMPOSER_END---'
    if [ \$COMPOSER_EXIT -ne 0 ]; then
        echo 'ERROR: composer install fehlgeschlagen'
        exit 1
    fi

    # PHPUnit-Tests ausführen
    echo '---TEST_START---'
    php bin/reset-test-db.php 2>&1
    vendor/bin/phpunit --colors=never 2>&1
    TEST_EXIT=\$?
    echo \"---TEST_EXIT:\$TEST_EXIT---\"
    echo '---TEST_END---'

    # Bereinigung: ohne Dev-Dependencies installieren
    echo '---CLEAN_START---'
    composer install --no-dev --no-interaction --no-ansi --prefer-dist --optimize-autoloader 2>&1
    CLEAN_EXIT=\$?
    echo '---CLEAN_END---'
    if [ \$CLEAN_EXIT -ne 0 ]; then
        echo 'ERROR: composer install --no-dev fehlgeschlagen'
        exit 1
    fi

    exit \$TEST_EXIT
" 2>&1 | tee "$DEPLOY_LOG"

# Exit-Code des SSH-Prozesses (nicht der von tee) übernehmen
SSH_EXIT=${PIPESTATUS[0]}
SSH_OUTPUT=$(cat "$DEPLOY_LOG")
rm -f "$DEPLOY_LOG"

# 🔍 Verarbeite den Output
IN_GIT=0
IN_COMMIT=0
IN_COMPOSER=0
IN_TEST=0
IN_CLEAN=0
IN_BRANCH=0
HAS_ERRORS=0
TEST_EXIT_CODE=""

while IFS= read -r line; do
    # Durch das erzwungene TTY (-t) enden Zeilen auf \r\n – Windows-Zeilenende entfernen
    line="${line%$'\r'}"

    # Schritt-Trenner
    if [[ "$line" == "---BRANCH_CHECK---" ]]; then
        IN_BRANCH=1
        IN_GIT=0
        IN_COMMIT=0
        IN_COMPOSER=0
        IN_TEST=0
        IN_CLEAN=0
        continue
    elif [[ "$line" == "---BRANCH_OK---" ]]; then
        IN_BRANCH=0
        continue
    elif [[ "$line" == "---GIT_START---" ]]; then
        IN_GIT=1
        IN_COMMIT=0
        IN_COMPOSER=0
        IN_TEST=0
        IN_CLEAN=0
        continue
    elif [[ "$line" == "---GIT_END---" ]]; then
        IN_GIT=0
        continue
    elif [[ "$line" == "---COMMIT_START---" ]]; then
        IN_COMMIT=1
        IN_GIT=0
        IN_COMPOSER=0
        IN_TEST=0
        IN_CLEAN=0
        continue
    elif [[ "$line" == "---COMMIT_END---" ]]; then
        IN_COMMIT=0
        continue
    elif [[ "$line" == "---COMPOSER_START---" ]]; then
        IN_COMPOSER=1
        IN_GIT=0
        IN_COMMIT=0
        IN_TEST=0
        IN_CLEAN=0
        continue
    elif [[ "$line" == "---COMPOSER_END---" ]]; then
        IN_COMPOSER=0
        continue
    elif [[ "$line" == "---TEST_START---" ]]; then
        IN_TEST=1
        IN_GIT=0
        IN_COMMIT=0
        IN_COMPOSER=0
        IN_CLEAN=0
        continue
    elif [[ "$line" == "---TEST_EXIT:"* ]]; then
        TEST_EXIT_CODE="${line#---TEST_EXIT:}"
        TEST_EXIT_CODE="${TEST_EXIT_CODE%---}"
        continue
    elif [[ "$line" == "---TEST_END---" ]]; then
        IN_TEST=0
        continue
    elif [[ "$line" == "---CLEAN_START---" ]]; then
        IN_CLEAN=1
        IN_GIT=0
        IN_COMMIT=0
        IN_COMPOSER=0
        IN_TEST=0
        continue
    elif [[ "$line" == "---CLEAN_END---" ]]; then
        IN_CLEAN=0
        continue
    fi

    # Branch-Check-Ausgabe
    if [ $IN_BRANCH -eq 1 ]; then
        if [[ "$line" == *"WARN:"* ]]; then
            print_warning "${line#WARN: }"
        fi
        continue
    fi

    # Testausgabe: immer vollständig anzeigen
    if [ $IN_TEST -eq 1 ]; then
        echo "$line"
        continue
    fi

    # Commit-Info als Info-Text ausgeben
    if [ $IN_COMMIT -eq 1 ]; then
        COMMIT_ID="${line%%|*}"
        COMMIT_MSG="${line#*|}"
        print_info "Deployter Commit: ${BOLD}${COMMIT_ID}${NC} – ${COMMIT_MSG}"
        continue
    fi

    # Fehlerbehandlung (bewusst enge Muster, damit Dateinamen wie
    # "TravelError.php" oder git-Stat-Zeilen nicht als Fehler gelten)
    if [[ "$line" == "ERROR: "* ]]; then
        print_error "${line#"ERROR: "}"
        HAS_ERRORS=1
    elif [[ "$line" =~ ([Ee]rror:|[Ff]atal:|[Ff]atal[[:space:]]error|[Pp]arse[[:space:]]error|[Uu]ncaught|[Ss]tack[[:space:]]trace) ]]; then
        if [ $IN_GIT -eq 1 ]; then
            print_error "[git pull] $line"
        elif [ $IN_COMPOSER -eq 1 ]; then
            print_error "[composer] $line"
        elif [ $IN_CLEAN -eq 1 ]; then
            print_error "[composer --no-dev] $line"
        else
            print_error "$line"
        fi
        HAS_ERRORS=1
    elif [[ "$line" == *"warning:"* ]] || [[ "$line" == *"Warning"* ]] || [[ "$line" == *"WARN"* ]]; then
        if [ $IN_GIT -eq 1 ]; then
            print_warning "[git pull] $line"
        elif [ $IN_COMPOSER -eq 1 ]; then
            print_warning "[composer] $line"
        elif [ $IN_CLEAN -eq 1 ]; then
            print_warning "[composer --no-dev] $line"
        else
            print_warning "$line"
        fi
    fi
done <<< "$SSH_OUTPUT"

# 🧪 Testergebnis auswerten (immer anzeigen)
if [ -n "$TEST_EXIT_CODE" ]; then
    if [ "$TEST_EXIT_CODE" -eq 0 ]; then
        print_success "PHPUnit-Tests erfolgreich (Exit-Code 0)"
    else
        print_error "PHPUnit-Tests fehlgeschlagen (Exit-Code: $TEST_EXIT_CODE)"
        HAS_ERRORS=1
    fi
else
    print_warning "Kein Testergebnis vom Server empfangen"
fi

# 🎮 Arcade-Build auf den Server übertragen (nur wenn der Deploy sauber war).
# Die WASM-Artefakte sind bewusst nicht im Repo, weil der Server kein Go hat.
if [ $SSH_EXIT -eq 0 ] && [ $HAS_ERRORS -eq 0 ]; then
    UPLOAD_OK=1
    UPLOAD_PATHS=()
    for FILE in "${ARCADE_UPLOAD_FILES[@]}"; do
        if [ ! -f "$ARCADE_OUT_DIR/$FILE" ]; then
            print_error "Arcade-Datei fehlt: $ARCADE_OUT_DIR/$FILE"
            UPLOAD_OK=0
            break
        fi
        UPLOAD_PATHS+=("$ARCADE_OUT_DIR/$FILE")
    done

    # Beide Dateien in einem Aufruf: wasm_exec.js und Binary gehören zusammen.
    if [ $UPLOAD_OK -eq 1 ]; then
        if ! scp -i "$SSH_KEY" -o StrictHostKeyChecking=no -o LogLevel=QUIET \
            "${UPLOAD_PATHS[@]}" "$SERVER:$TARGET_DIR/public/arcade/rocket-boost/"; then
            print_error "Upload des Arcade-Builds fehlgeschlagen"
            UPLOAD_OK=0
        fi
    fi

    if [ $UPLOAD_OK -eq 1 ]; then
        print_success "Arcade-Build hochgeladen (${ARCADE_UPLOAD_FILES[*]})"
    else
        HAS_ERRORS=1
    fi
fi

# ✅ Erfolgsmeldungen (nur wenn keine Fehler aufgetreten sind)
if [ $HAS_ERRORS -eq 0 ]; then
    print_success "Branch-Check (main) erfolgreich"
    print_success "'git pull home main' erfolgreich"
    print_success "'composer install' (mit Dev-Dependencies) erfolgreich"
    print_success "'composer install --no-dev' erfolgreich"
fi

# ❌ Fehlerbehandlung
if [ $SSH_EXIT -ne 0 ] || [ $HAS_ERRORS -ne 0 ]; then
    handle_error "Ein oder mehrere Befehle sind fehlgeschlagen"
fi

# 🎉 Abschluss
echo ""
echo -e "${BOLD}${BLUE}========================================${NC}"
echo -e "${BOLD}${GREEN}   ✓ Alle Schritte erfolgreich!        ${NC}"
echo -e "${BOLD}${BLUE}========================================${NC}"
