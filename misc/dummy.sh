#!/bin/bash

if ! perl compile.pl install; then
	echo "Unable to compile... Aborting.\n"
	exit
else
	cp ../taralink/taralink /root/taransvar
fi

