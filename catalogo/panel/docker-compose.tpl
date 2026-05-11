services:
  {{EMPRESA}}_panel:
    image: php:8.2-apache
    container_name: {{EMPRESA}}_panel
    restart: always
    environment:
      - DB_HOST=infra_users_db
      - DB_PORT=3306
      - DB_NAME=users_db
      - DB_USER=users_user
      - DB_PASSWORD={{DB_PASSWORD}}
    volumes:
      - {{RUTA_DATOS}}/panel:/var/www/html
    networks:
      - {{EMPRESA}}_net
      - infra_net
    ports:
      - "{{PUERTO}}:80"

networks:
  {{EMPRESA}}_net:
    external: true
  infra_net:
    external: true