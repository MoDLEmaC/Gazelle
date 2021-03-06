s/'SITE_NAME', *'/&Gazelle Dev/
s/'SITE_HOST', *'/&localhost/

s/'NONSSL_SITE_URL', *'/&localhost:8080/
s/'SSL_SITE_URL', *'/&localhost:8080/
s/'SSL_HOST', *'/&localhost/

s/('SITE_URL', *'http)s/\1/

s/'(SERVER_ROOT(_LIVE)?)', *'\/path/'\1', '\/var\/www/

s|'ANNOUNCE_HTTP_URL', *'|&http://localhost:34000|
s|'ANNOUNCE_HTTPS_URL', *'|&https://localhost:3400|

s/('SQLHOST', *')localhost/\1mysql/
s/('SPHINX(QL)?_HOST', *')(localhost|127\.0\.0\.1)/\1sphinxsearch/

s|('host' *=>) *'unix:///var/run/memcached.sock'(, *'port' *=>) *0|\1 'memcached'\2 11211|

s/('(DEBUG_MODE|DISABLE_TRACKER|DISABLE_IRC)',) *false/\1 true/

s|'SOURCE', *'|&DEV|
