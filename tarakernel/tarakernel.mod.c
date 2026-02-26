#include <linux/module.h>
#include <linux/export-internal.h>
#include <linux/compiler.h>

MODULE_INFO(name, KBUILD_MODNAME);

__visible struct module __this_module
__section(".gnu.linkonce.this_module") = {
	.name = KBUILD_MODNAME,
	.init = init_module,
#ifdef CONFIG_MODULE_UNLOAD
	.exit = cleanup_module,
#endif
	.arch = MODULE_ARCH_INIT,
};



static const struct modversion_info ____versions[]
__used __section("__versions") = {
	{ 0xd272d446, "__fentry__" },
	{ 0x296b9459, "strchr" },
	{ 0xdd6830c7, "sprintf" },
	{ 0x90a48d82, "__ubsan_handle_out_of_bounds" },
	{ 0x43a349ca, "strlen" },
	{ 0xbd03ed67, "__ref_stack_chk_guard" },
	{ 0xbd03ed67, "random_kmalloc_seed" },
	{ 0xfaabfe5e, "kmalloc_caches" },
	{ 0xc064623f, "__kmalloc_cache_noprof" },
	{ 0x17545440, "strstr" },
	{ 0x2435d559, "strncmp" },
	{ 0xd272d446, "__stack_chk_fail" },
	{ 0xc609ff70, "strncpy" },
	{ 0xbd069841, "kstrtoull" },
	{ 0xf52f8b44, "__kvmalloc_node_noprof" },
	{ 0x27683a56, "memset" },
	{ 0x888b8f57, "strcmp" },
	{ 0x173ec8da, "sscanf" },
	{ 0x1f55c5b2, "kstrtoll" },
	{ 0xfa1add34, "__netlink_kernel_create" },
	{ 0x80441a3f, "nf_register_net_hook" },
	{ 0x95221fe6, "__alloc_skb" },
	{ 0x6466ae59, "__nlmsg_put" },
	{ 0x6c4d0ef3, "netlink_unicast" },
	{ 0x9479a1e8, "strnlen" },
	{ 0xe54e0a6b, "__fortify_panic" },
	{ 0x7d240728, "init_net" },
	{ 0xfb0f9d2d, "nf_unregister_net_hook" },
	{ 0xcb8b6ec6, "kfree" },
	{ 0xe8213e80, "_printk" },
	{ 0x3a0aa8c7, "netlink_kernel_release" },
	{ 0xd272d446, "__x86_return_thunk" },
	{ 0xbebe66ff, "module_layout" },
};

static const u32 ____version_ext_crcs[]
__used __section("__version_ext_crcs") = {
	0xd272d446,
	0x296b9459,
	0xdd6830c7,
	0x90a48d82,
	0x43a349ca,
	0xbd03ed67,
	0xbd03ed67,
	0xfaabfe5e,
	0xc064623f,
	0x17545440,
	0x2435d559,
	0xd272d446,
	0xc609ff70,
	0xbd069841,
	0xf52f8b44,
	0x27683a56,
	0x888b8f57,
	0x173ec8da,
	0x1f55c5b2,
	0xfa1add34,
	0x80441a3f,
	0x95221fe6,
	0x6466ae59,
	0x6c4d0ef3,
	0x9479a1e8,
	0xe54e0a6b,
	0x7d240728,
	0xfb0f9d2d,
	0xcb8b6ec6,
	0xe8213e80,
	0x3a0aa8c7,
	0xd272d446,
	0xbebe66ff,
};
static const char ____version_ext_names[]
__used __section("__version_ext_names") =
	"__fentry__\0"
	"strchr\0"
	"sprintf\0"
	"__ubsan_handle_out_of_bounds\0"
	"strlen\0"
	"__ref_stack_chk_guard\0"
	"random_kmalloc_seed\0"
	"kmalloc_caches\0"
	"__kmalloc_cache_noprof\0"
	"strstr\0"
	"strncmp\0"
	"__stack_chk_fail\0"
	"strncpy\0"
	"kstrtoull\0"
	"__kvmalloc_node_noprof\0"
	"memset\0"
	"strcmp\0"
	"sscanf\0"
	"kstrtoll\0"
	"__netlink_kernel_create\0"
	"nf_register_net_hook\0"
	"__alloc_skb\0"
	"__nlmsg_put\0"
	"netlink_unicast\0"
	"strnlen\0"
	"__fortify_panic\0"
	"init_net\0"
	"nf_unregister_net_hook\0"
	"kfree\0"
	"_printk\0"
	"netlink_kernel_release\0"
	"__x86_return_thunk\0"
	"module_layout\0"
;

MODULE_INFO(depends, "");


MODULE_INFO(srcversion, "3538693AB006105D80418FF");
