#!/bin/bash
# Run PHP-FPM and lighttpd in the foreground. When either one exits, stop the
# other and exit with its status, so that the container stops (and its restart
# policy applies) instead of running half-dead.

php-fpm -F &
fpm=$!
lighttpd -D -f /etc/lighttpd/lighttpd.conf &
httpd=$!

trap 'kill -TERM "$fpm" "$httpd" 2>/dev/null' TERM INT

status=0
wait -n "$fpm" "$httpd" || status=$?
kill -TERM "$fpm" "$httpd" 2>/dev/null
wait
exit "$status"
