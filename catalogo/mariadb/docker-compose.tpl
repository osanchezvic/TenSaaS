services:
  mariadb:
    container_name: {{EMPRESA}}_mariadb
    image: mariadb:latest
    restart: always
    environment:
      - MYSQL_ROOT_PASSWORD={{DB_ROOT_PASSWORD}}
      - MYSQL_DATABASE={{DB_NAME}}
      - MYSQL_USER={{DB_USER}}
      - MYSQL_PASSWORD={{DB_PASSWORD}}
    volumes:
      - {{RUTA_DATOS}}/mariadb:/var/lib/mysql
    networks:
      - {{EMPRESA}}_net
    ports:
      - "{{PUERTO}}:3306"

networks:
  {{EMPRESA}}_net:
    external: true
