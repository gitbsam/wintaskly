@echo off
:: Force l'affichage et l'écriture des accents/émojis en UTF-8
chcp 65001 >nul
title Système de Déploiement Wintaskly (Version Propre)

:: Configuration des chemins
set "PROJECT_DIR=C:\Users\zawu\wintaskly\televerse_wintaskly"
set "INIT_PHP=%PROJECT_DIR%\includes\init.php"

:verification
cls
echo ====================================================
echo       🔍 CONNEXION ET VÉRIFICATION DU SYSTÈME
echo ====================================================
echo.
echo Analyse des prérequis en cours...
echo.

:: 1. Vérification de Git
git --version >nul 2>&1
if %errorlevel% equ 0 (
    set "GIT_STATUS=✅ INSTALLÉ (Prêt)"
) else (
    set "GIT_STATUS=❌ NON TROUVÉ (Vous devez installer Git pour Windows !)"
)

:: 2. Vérification du dossier local
if exist "%PROJECT_DIR%" (
    set "DIR_STATUS=✅ TROUVÉ (Dossier accessible)"
) else (
    set "DIR_STATUS=❌ INTROUVABLE (Vérifiez le chemin %PROJECT_DIR%)"
)

:: 3. Vérification du fichier init.php et extraction de la version
if exist "%INIT_PHP%" (
    set "INIT_STATUS=✅ TROUVÉ"
    set "WT_VERSION="
    for /f "usebackq tokens=4 delims='" %%A in (`findstr /C:"define('WT_VERSION'" "%INIT_PHP%"`) do set "WT_VERSION=%%A"
) else (
    set "INIT_STATUS=❌ INTROUVABLE (Le fichier init.php manque)"
    set "WT_VERSION="
)

if "%WT_VERSION%"=="" set "WT_VERSION=8.7.7"

:: Affichage du bilan complet
echo ====================================================
echo                    BILAN DES COMPOSANTS            
echo ====================================================
echo [LOGICIELS]  Git local       : %GIT_STATUS%
echo [DOSSIERS]   Projet Racine   : %DIR_STATUS%
echo [FICHIERS]   Fichier init.php: %INIT_STATUS%
echo [VERSION]    Version détectée: V%WT_VERSION%
echo ====================================================
echo.

:: Boucle de confirmation
set "confirm="
set /p confirm="👉 Appuyez sur ENTRÉE pour valider et continuer, ou tapez 'N' pour réessayer : "

if /i "%confirm%"=="N" (
    echo.
    echo 🔄 Re-tentative de vérification...
    timeout /t 2 >nul
    goto verification
)

:menu
cls
echo ====================================================
echo        LANCEUR GIT AUTOMATIQUE - WINTASKLY
echo ====================================================
echo.
echo [1] Initialiser le dépôt et Push complet (Premier commit)
echo [2] Mettre à jour la version (Push de latest.json uniquement)
echo [3] Relancer la vérification du système
echo [4] Quitter
echo.
set "choix="
set /p choix="Choisissez une option (1-4) : "

if "%choix%"=="1" goto initial_push
if "%choix%"=="2" goto update_push
if "%choix%"=="3" goto verification
if "%choix%"=="4" goto eof
goto menu

:initial_push
cls
echo 🚀 Lancement de l'initialisation pour la version V%WT_VERSION%...
echo.

:: Nettoyage automatique des fichiers ZIP locaux avant toute action Git
echo 🧹 Analyse et nettoyage des fichiers ZIP résiduels...
if exist "*.zip" (
    echo 🗑️ Fichiers ZIP détectés ! Suppression locale automatique...
    del /f /q "*.zip"
) else (
    echo ✅ Aucun fichier ZIP nuisible détecté.
)
echo.

:: Initialisation Git
git init
git branch -M main

:: Nettoyage et configuration du remote origin
git remote remove origin >nul 2>&1
git remote add origin https://github.com/gitbsam/wintaskly.git

echo 📝 Création du fichier .gitignore (avec exclusion du script de déploiement)...
echo # Configuration locale ^(créée depuis config.example.php^)> .gitignore
echo config.php>> .gitignore
echo.>> .gitignore
echo # Script de déploiement local>> .gitignore
echo deploy_wintaskly.bat>> .gitignore
echo.>> .gitignore
echo # Dépendances Node ^(pour la compilation Tailwind^)>> .gitignore
echo node_modules/>> .gitignore
echo.>> .gitignore
echo # Logs>> .gitignore
echo *.log>> .gitignore
echo.>> .gitignore
echo # Éditeurs / OS>> .gitignore
echo .DS_Store>> .gitignore
echo .idea/>> .gitignore
echo .vscode/>> .gitignore
echo *.swp>> .gitignore
echo.>> .gitignore
echo # Wintaskly V8 : frontend build chain ^(Tailwind dans /frontend/^)>> .gitignore
echo # node_modules/ matche déjà partout grâce à la règle ci-dessus, mais explicite ici>> .gitignore
echo # pour rappeler aux contributeurs qu'il faut faire `cd frontend ^&^& npm install`.>> .gitignore
echo frontend/node_modules/>> .gitignore
echo.>> .gitignore
echo # Output Tailwind compilé : on le garde dans le repo car les serveurs LWS>> .gitignore
echo # n'ont pas Node.js — c'est l'output qui est uploadé en prod.>> .gitignore
echo # ^(Décommentez la ligne suivante si vous voulez l'exclure du repo^)>> .gitignore
echo # media/tailwind/css/tailwind.css>> .gitignore

echo.
echo 📂 Ajout des fichiers propres et création du commit...
git add .
git commit -m "🎉 Initial commit — Wintaskly V%WT_VERSION% (premier release public)"

echo 📤 Envoi forcé vers GitHub...
git push -u origin main --force

echo.
echo ✅ Dépôt initialisé proprement et envoyé !
pause
goto menu

:update_push
cls
echo 📡 Préparation de la mise à jour pour la version v%WT_VERSION%...
echo ⏳ Push de latest.json en cours...
echo.

git add latest.json
git commit -m "📡 v%WT_VERSION% available"
git push origin main

echo.
echo ✅ Mise à jour de latest.json envoyée sur GitHub !
pause
goto menu

:eof
echo.
echo Au revoir !
timeout /t 2 >nul