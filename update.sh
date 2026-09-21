#!/bin/bash

# ⚙️  KONFIGURATION (hier anpassen)
SERVER="mmrtk@mmrtk.lima-ssh.de"
SSH_KEY="$HOME/.ssh/id_rsa"    # Pfad zu deinem privaten SSH-Key
TARGET_DIR="sinclear.de/api"   # Zielverzeichnis auf dem Server

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
print_step "1/5: Verbinde mit Server ($SERVER)..."
if ! ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o LogLevel=QUIET "$SERVER" "echo SSH_OK" >/dev/null 2>&1; then
    handle_error "SSH-Verbindung fehlgeschlagen! Überprüfe Server, Benutzer und SSH-Key."
fi
print_success "SSH-Verbindung erfolgreich"

# 2️⃣  Alle Befehle in EINER SSH-Sitzung ausführen (vermeidet wiederholte Willkommensnachrichten)
print_step "2/5: Prüfe Branch (main) und starte git pull..."
print_step "3/5: Führe composer install (mit Dev-Dependencies) aus..."
print_step "4/5: Führe PHPUnit-Tests aus..."
print_step "5/5: Führe composer install --no-dev aus (Bereinigung)..."

SSH_OUTPUT=$(ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o LogLevel=QUIET "$SERVER" "
    # Wechsle in das Verzeichnis
    cd $TARGET_DIR || { echo 'ERROR: Verzeichniswechsel fehlgeschlagen'; exit 1; }

    # Sicherstellen, dass main-Branch verwendet wird
    echo '---BRANCH_CHECK---'
    CURRENT_BRANCH=\$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
    if [ \"\$CURRENT_BRANCH\" != \"main\" ]; then
        echo \"WARN: Aktueller Branch ist '\$CURRENT_BRANCH' – Wechsle zu main...\"
        git checkout main 2>&1
        if [ \$? -ne 0 ]; then
            echo 'ERROR: Branch-Wechsel zu main fehlgeschlagen'
            exit 1
        fi
    fi
    echo '---BRANCH_OK---'

    # git pull
    echo '---GIT_START---'
    git pull origin main 2>&1
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
    composer dump-autoload
    composer install --no-interaction --prefer-dist --optimize-autoloader 2>&1
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
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader 2>&1
    CLEAN_EXIT=\$?
    echo '---CLEAN_END---'
    if [ \$CLEAN_EXIT -ne 0 ]; then
        echo 'ERROR: composer install --no-dev fehlgeschlagen'
        exit 1
    fi

    exit \$TEST_EXIT
" 2>&1)

SSH_EXIT=$?

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

    # Fehlerbehandlung
    if [[ "$line" == "ERROR: "* ]]; then
        print_error "${line#"ERROR: "}"
        HAS_ERRORS=1
    elif [[ "$line" == *"error:"* ]] || [[ "$line" == *"fatal:"* ]] || [[ "$line" == *"Error"* ]] || [[ "$line" == *"Exception"* ]]; then
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

# ✅ Erfolgsmeldungen (nur wenn keine Fehler aufgetreten sind)
if [ $HAS_ERRORS -eq 0 ]; then
    print_success "Branch-Check (main) erfolgreich"
    print_success "'git pull origin main' erfolgreich"
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
