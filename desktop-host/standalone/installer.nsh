!macro customInstall
  ReadRegDWORD $0 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Installed"
  ${If} $0 != 1
    DetailPrint "Installing the Microsoft C++ runtime required by bundled PHP and MySQL..."
    ExecWait '"$INSTDIR\resources\classroom\vc_redist.x64.exe" /install /quiet /norestart' $0
    ${If} $0 != 0
    ${AndIf} $0 != 3010
      MessageBox MB_ICONSTOP "Microsoft runtime installation failed (code $0). Install the included vc_redist.x64.exe, then run CCIS Classroom."
    ${EndIf}
  ${EndIf}
!macroend
