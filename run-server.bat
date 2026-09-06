@echo off
title Wintaskly - Environnement Dev
cls

echo ========================================================
echo   Demarrage de l'environnement de Developpement
echo ========================================================

echo.
echo [1/4] Demarrage du serveur PHP sur http://localhost:8003...
:: Lance le serveur PHP dans une nouvelle fenetre standard
start "Serveur PHP" php -S localhost:8003

echo.
echo [2/4] Verifier si le dossier frontend existe
:: Verifier si le dossier frontend existe pour s'y positionner
if exist "frontend" (
    cd frontend
    echo [Info] Navigation vers le dossier 'frontend'...
)

echo.
echo [3/4] Verifier si le fichier source CSS existe
:: Verifier si le fichier source CSS existe
if not exist "src\input.css" (
    echo [Erreur] Le fichier src\input.css est introuvable. Veuillez verifier votre installation.
    pause
    exit /b
)

echo.
echo [4/4] Demarrage de Tailwind CSS (watch)...
:: Lancer Tailwind CSS en mode watch dans une fenetre separee
start "Tailwind CSS - Wintaskly" cmd /k "cd /d C:\Users\zawu\Groupe TADJIDINE SASU\Wintaskly && npx @tailwindcss/cli -i ./frontend/src/input.css -o ./media/wintaskly/css/tailwind.css --watch"

echo.
echo Appuyez sur Ctrl+C ou fermez cette fenetre pour arreter le serveur.

echo.
echo ====================================================
echo   Tout est lance ! Laisse les fenetres ouvertes.
echo   PHP     : http://localhost:8003
echo   Tailwind: compilation automatique active
echo ====================================================
echo.
pause