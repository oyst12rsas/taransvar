os = $(uname -r)
echo "Os: $os\n"
cd ~/programming/tarakernel
make -C .
sudo rmmod tarakernel
sudo rm /lib/modules/6.17.0-14-generic/tarakernel.ko
#sudo rm /lib/modules/$(shell uname -r)/tarakernel.ko
sudo cp tarakernel.ko /lib/modules/6.17.0-14-generic
#sudo cp tarakernel.ko /lib/modules/$(shell uname -r)
sudo depmod -a
sudo modprobe tarakernel
sudo lsmod | grep tarakernel
sudo ls /lib/modules/6.17.0-14-generic -l | grep tarakernel
#sudo ls /lib/modules/$(shell uname -r) -l | grep tarakernel
