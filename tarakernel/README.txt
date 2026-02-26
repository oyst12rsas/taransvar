1. Execute make first. This will result in a netlinkKernel.ko output among many others.
2. Execute $ gcc netlinkUser.c -o netlinkUser
3. Insert kernel module by :$ sudo insmod netlinkKernel.ko      
4. Run ./netlinkUser to see message and run dmesg to see debug messages
5. Remove module by : $ sudo rmmod netlinkKernel          
6. Finally make clean to remove output files.
