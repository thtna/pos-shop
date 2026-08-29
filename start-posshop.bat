@echo off
rem ============================================
rem  POS SHOP - Khoi dong web ban hang F&B
rem  Double-click file nay de chay web POS Shop
rem ============================================
title POS Shop - Dang chay...

rem 1. Mo web bang trinh duyet (Chrome hoac Edge)
start "" /max "http://127.0.0.1:8765"

rem 2. Chay server phuc vu web (thu nho, khong dong cua so to)
cd /d "C:\Users\LNV\.zcode\workspace\default\pos-shop"
echo.
echo  ============================================
echo    POS SHOP dang chay tai: http://127.0.0.1:8765
echo    - KHONG dong cua so nay khi dang ban hang
echo    - De tat: dong cua so den nay hoac nhan Ctrl+C
echo  ============================================
echo.
python -m http.server 8765 2>nul || py -m http.server 8765 2>nul || (
  echo [Loi] Khong tim thay Python tren may!
  echo Hay cai Python tu https://www.python.org roi thu lai.
  pause
)
