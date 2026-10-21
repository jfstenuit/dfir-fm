#!/bin/bash
# Run PHP-FPM and lighttpd in the foreground. When either one exits, stop the
# other and exit with its status, so that the container stops (and its restart
# policy applies) instead of running half-dead.
#
# First, as root: give www-data the uid/gid of APP_UID/APP_GID (default 82,
# the image's), so that the processes can write to host directories owned by
# that user, and hand the Docker volumes mounted under /data over to it. Host
# directories (APP_FILES_DIR, APP_LOG_DIR set) are never changed.

uid=${APP_UID:-82}
gid=${APP_GID:-82}
if [[ ! $uid =~ ^[0-9]+$ || ! $gid =~ ^[0-9]+$ || $uid == 0 || $gid == 0 ]]; then
    echo "start.sh: uid and gid must be numbers other than 0 (uid=$uid, gid=$gid)" >&2
    exit 1
fi
if [[ $(id -g www-data) != "$gid" ]]; then
    groupmod -o -g "$gid" www-data || exit 1
fi
if [[ $(id -u www-data) != "$uid" ]]; then
    usermod -o -u "$uid" -g "$gid" www-data || exit 1
fi
volumes=(/data/database)
[[ -z $APP_FILES_DIR ]] && volumes+=(/data/files)
[[ -z $APP_LOG_DIR ]] && volumes+=(/data/logs)
for dir in "${volumes[@]}"; do
    owner=$(stat -c %u:%g "$dir")
    if [[ $owner != "$uid:$gid" ]]; then
        echo "start.sh: chown -R $uid:$gid $dir (was $owner)" >&2
        chown -R "$uid:$gid" "$dir" || exit 1
    fi
done

php-fpm -F &
fpm=$!
# fd 3: the access log (lighttpd.conf)
lighttpd -D -f /etc/lighttpd/lighttpd.conf 3>&1 &
httpd=$!

trap 'kill -TERM "$fpm" "$httpd" 2>/dev/null' TERM INT

status=0
wait -n "$fpm" "$httpd" || status=$?
kill -TERM "$fpm" "$httpd" 2>/dev/null
wait
exit "$status"
