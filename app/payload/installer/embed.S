/* Recomp Store installer: the store's own zip, built in (make EMBED_ZIP=...).
 * SPDX-License-Identifier: GPL-3.0-or-later */
    .section .rodata
    .balign 16
    .global rs_embedded_zip
    .global rs_embedded_zip_end
rs_embedded_zip:
    .incbin RS_EMBED_PATH
rs_embedded_zip_end:
    .byte 0
