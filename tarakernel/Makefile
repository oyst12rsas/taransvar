CONFIG_MODULE_SIG=n
obj-m += tarakernel.o
PWD := $(CURDIR)
#INC_DIR = /usr/include/x86_64-linux-gnu
#INC_DIR = /usr/include
CFLAGS=-I/usr/include
all:
	make -C /lib/modules/$(shell uname -r)/build M=$(PWD) -I/usr/include modules
clean:
	make -C /lib/modules/$(shell uname -r)/build M=$(PWD) clean

