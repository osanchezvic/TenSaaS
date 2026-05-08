#!/bin/bash

# NPM API helper functions
# Requiere: NPM_URL, NPM_USER, NPM_PASSWORD

npm_get_token() {
    curl -s -X POST "${NPM_URL}/api/tokens" \
        -H "Content-Type: application/json" \
        -d "{\"identity\": \"${NPM_USER}\", \"secret\": \"${NPM_PASSWORD}\"}" \
        | jq -r .token
}

npm_add_proxy() {
    local domain=$1
    local forward_host=$2
    local forward_port=$3
    local cert_id=$4
    local token=$5

    local advanced_config='
satisfy any;
allow 127.0.0.1;
auth_request /authelia;
auth_request_set $target_url $scheme://$http_host$request_uri;
auth_request_set $user $upstream_http_remote_user;
auth_request_set $groups $upstream_http_remote_groups;
proxy_set_header Remote-User $user;
proxy_set_header Remote-Groups $groups;
error_page 401 =302 /authelia/?rd=$target_url;
'

    curl -s -X POST "${NPM_URL}/api/nginx/proxy-hosts" \
        -H "Authorization: Bearer ${token}" \
        -H "Content-Type: application/json" \
        -d "{
            \"domain_names\": [\"${domain}\"],
            \"forward_scheme\": \"http\",
            \"forward_host\": \"${forward_host}\",
            \"forward_port\": ${forward_port},
            \"certificate_id\": ${cert_id},
            \"ssl_forced\": true,
            \"block_exploits\": true,
            \"advanced_config\": \"$(echo "$advanced_config" | sed ':a;N;$!ba;s/\n/\\n/g')\",
            \"enabled\": true
        }"
}
