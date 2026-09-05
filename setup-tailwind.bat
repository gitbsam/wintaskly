@echo off
setlocal

echo ========================================================
echo   Initialisation de l'environnement Node.js et Tailwind
echo ========================================================

:: Étape 1 : Vérifier si le dossier frontend existe, sinon le créer et y entrer
if not exist "frontend" (
    echo [1/5] Creation du dossier 'frontend'...
    mkdir frontend
) else (
    echo [1/5] Le dossier 'frontend' existe deja.
)
cd frontend

:: Étape 2 : Vérifier si package.json existe, sinon le créer avec npm init -y
if not exist "package.json" (
    echo [2/5] Creation du fichier 'package.json'...
    call npm init -y
) else (
    echo [2/5] Le fichier 'package.json' existe deja.
)

:: Étape 3 : Vérifier si node_modules et package-lock.json existent, sinon installer Tailwind CSS (version 4.3.3)
if not exist "node_modules" (
    goto INSTALL_TAILWIND
)
if not exist "package-lock.json" (
    goto INSTALL_TAILWIND
)
echo [3/5] Les dependances et node_modules existent deja.
goto STEP_SRC

:INSTALL_TAILWIND
echo [3/5] Installation de Tailwind CSS (version 4.3.3)...
call npm install tailwindcss@4.3.3

:: Étape 4 : Vérifier si le dossier src existe, sinon le créer
:STEP_SRC
if not exist "src" (
    echo [4/5] Creation du dossier 'src'...
    mkdir src
) else (
    echo [4/5] Le dossier 'src' existe deja.
)

:: Étape 5 : Vérifier si src/input.css existe, sinon le creer avec la configuration de base
if not exist "src\input.css" (
    echo [5/5] Creation du fichier 'src/input.css'...
    (
        echo /* src/input.css */
        echo @import "tailwindcss^";
    ) > src\input.css
) else (
    echo [5/5] Le fichier 'src/input.css' existe deja.
)

echo ========================================================
echo   Configuration terminee avec succes !
echo ========================================================
pause