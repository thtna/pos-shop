$ws = New-Object -ComObject WScript.Shell
$desktop = [Environment]::GetFolderPath('Desktop')
$lnk = $ws.CreateShortcut("$desktop\POS Shop.lnk")
$lnk.TargetPath = 'C:\Users\LNV\.zcode\workspace\default\pos-shop\start-posshop.bat'
$lnk.WorkingDirectory = 'C:\Users\LNV\.zcode\workspace\default\pos-shop'
$lnk.Description = 'Web ban hang POS Shop - F&B'
$lnk.WindowStyle = 7  # 7 = min
$lnk.IconLocation = 'C:\Windows\System32\shell32.dll,176'
$lnk.Save()
Write-Host "OK - Da tao: $desktop\POS Shop.lnk"
