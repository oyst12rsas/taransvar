CONFIG_MODULE_SIG := n
obj-m += tarakernel.o
KVER ?= $(shell uname -r)
PWD := $(CURDIR)

ccflags-y += -g

all:
	$(MAKE) -C /lib/modules/$(KVER)/build M=$(PWD) modules

clean:
	$(MAKE) -C /lib/modules/$(KVER)/build M=$(PWD) clean
