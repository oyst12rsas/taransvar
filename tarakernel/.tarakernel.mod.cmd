savedcmd_tarakernel.mod := printf '%s\n'   tarakernel.o | awk '!x[$$0]++ { print("./"$$0) }' > tarakernel.mod
