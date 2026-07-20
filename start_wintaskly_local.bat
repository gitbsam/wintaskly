@echo off
title Wintaskly - Environnement Dev
cls

echo ====================================================
echo   Demarrage de l'environnement Ecommerce...
echo ====================================================

echo.
echo [1/2] Demarrage du serveur PHP sur http://localhost:8000...
:: Lance le serveur PHP dans une nouvelle fenetre standard
start "Serveur PHP" php -S localhost:8000

echo.
echo [2/2] Demarrage de Tailwind CSS (watch)...
:: Lance Tailwind en mode watch depuis la racine du projet, dans une nouvelle fenêtre
start "Tailwind CSS - Wintaskly" cmd /k "cd /d C:\Users\zawu\wintaskly\televerse_wintaskly && npx @tailwindcss/cli -i ./frontend/src/input.css -o ./media/tailwind/css/tailwind.css --content "./**/*.php" --content "./media/wintaskly/js/**/*.js" --watch"

echo.
echo ====================================================
echo   Tout est lance ! Laisse les fenetres ouvertes.
echo   PHP     : http://localhost:8000
echo   Tailwind: compilation automatique active
echo ====================================================
echo.
pause