#ifndef TARASEC_DB_CREDENTIALS_H
#define TARASEC_DB_CREDENTIALS_H
#include <stdio.h>
#include <stdlib.h>
/* Generated private local configuration, never compiled into the binary. */
static int tarasec_db_password(char output[65]) {
    const char *path = getenv("TARASEC_DB_APP_PASSWORD_FILE");
    if (!path || !*path) path = "/etc/tarasec/db-app.password";
    FILE *file = fopen(path, "r");
    if (!file) return 0;
    size_t size = fread(output, 1, 64, file);
    int trailing = fgetc(file);
    int end = trailing == '\n' ? fgetc(file) : trailing;
    int valid = size == 64 && end == EOF && !ferror(file);
    fclose(file);
    if (!valid) return 0;
    for (size_t i = 0; i < 64; ++i)
        if (!((output[i] >= '0' && output[i] <= '9') ||
              (output[i] >= 'a' && output[i] <= 'f'))) return 0;
    output[64] = '\0';
    return 1;
}
#endif
