!include "LogicLib.nsh"

!define APP_NAME "Lyralink"
!define APP_VERSION "1.0.2"
!define APP_PUBLISHER "LyralinkAI"
!define APP_EXE "Lyralink.exe"
!define APP_ZIP_URL "http://lyralinkai.com/desktop-updates/Lyralink-win32-x64.zip"
!define APP_ZIP_FALLBACK_URL "http://lyralinkai.com/desktop-updates/download.php?channel=stable&source=web_installer&artifact=portable_zip&version=1.0.2"

OutFile "dist/${APP_NAME}-Web-Setup.exe"
InstallDir "$PROGRAMFILES64\${APP_NAME}"
RequestExecutionLevel admin
Unicode True

Page directory
Page instfiles
UninstPage uninstConfirm
UninstPage instfiles

Section "Install"
  SetOutPath "$TEMP"
  Delete "$TEMP\lyralink-app.zip"

  DetailPrint "Downloading application package..."
  nsExec::ExecToLog "powershell -NoProfile -ExecutionPolicy Bypass -Command $\"[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -UseBasicParsing -Uri '${APP_ZIP_URL}' -OutFile '$TEMP\lyralink-app.zip'$\""
  Pop $0

  ${If} $0 != "0"
    DetailPrint "Primary download failed, trying fallback URL..."
    Delete "$TEMP\lyralink-app.zip"
    nsExec::ExecToLog "powershell -NoProfile -ExecutionPolicy Bypass -Command $\"[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -UseBasicParsing -Uri '${APP_ZIP_FALLBACK_URL}' -OutFile '$TEMP\lyralink-app.zip'$\""
    Pop $1
    ${If} $1 != "0"
      MessageBox MB_ICONSTOP|MB_OK "Failed to connect and download the app package. Please check internet access or use the offline installer."
      Abort
    ${EndIf}
  ${EndIf}

  SetOutPath "$INSTDIR"
  DetailPrint "Extracting package..."
  nsExec::ExecToLog "powershell -NoProfile -ExecutionPolicy Bypass -Command $\"Expand-Archive -Path '$TEMP\lyralink-app.zip' -DestinationPath '$INSTDIR' -Force$\""
  Pop $2

  ${If} $2 != "0"
    DetailPrint "Expand-Archive unavailable, trying tar extraction fallback..."
    nsExec::ExecToLog 'tar -xf "$TEMP\lyralink-app.zip" -C "$INSTDIR"'
    Pop $3
    ${If} $3 != "0"
      MessageBox MB_ICONSTOP|MB_OK "Extraction failed. Please run the offline installer instead."
      Abort
    ${EndIf}
  ${EndIf}

  Delete "$TEMP\lyralink-app.zip"

  CreateDirectory "$SMPROGRAMS\${APP_NAME}"
  CreateShortcut "$SMPROGRAMS\${APP_NAME}\${APP_NAME}.lnk" "$INSTDIR\${APP_EXE}"
  CreateShortcut "$DESKTOP\${APP_NAME}.lnk" "$INSTDIR\${APP_EXE}"

  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_NAME}" "DisplayName" "${APP_NAME}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_NAME}" "Publisher" "${APP_PUBLISHER}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_NAME}" "DisplayVersion" "${APP_VERSION}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_NAME}" "UninstallString" "$INSTDIR\Uninstall.exe"
  WriteUninstaller "$INSTDIR\Uninstall.exe"
SectionEnd

Section "Uninstall"
  Delete "$DESKTOP\${APP_NAME}.lnk"
  Delete "$SMPROGRAMS\${APP_NAME}\${APP_NAME}.lnk"
  RMDir "$SMPROGRAMS\${APP_NAME}"

  RMDir /r "$INSTDIR"
  DeleteRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${APP_NAME}"
SectionEnd