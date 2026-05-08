services:
  {{EMPRESA}}_prestashop:
    image: prestashop/prestashop:latest
    container_name: {{EMPRESA}}_prestashop
    restart: always
    environment:
      - DB_SERVER={{EMPRESA}}_mariadb:3306
      - DB_NAME={{DB_NAME}}
      - DB_USER={{DB_USER}}
      - DB_PASSWORD={{DB_PASSWORD}}
      - PS_DOMAIN=localhost:{{PUERTO}}
      - PS_INSTALL_AUTO=1
      - PS_ERASE_DB=0
      - PS_ADMIN_EMAIL=admin@{{EMPRESA}}.com
      - PS_ADMIN_PASSWORD={{ADMIN_PASSWORD}}
    ports:
      - "{{PUERTO}}:80"
    volumes:
      - {{RUTA_DATOS}}/prestashop_html:/var/www/html
      - {{RUTA_DATOS}}/prestashop_img:/var/www/html/img
      - {{RUTA_DATOS}}/prestashop_mails:/var/www/html/mails
      - {{RUTA_DATOS}}/prestashop_modules:/var/www/html/modules
      - {{RUTA_DATOS}}/prestashop_themes:/var/www/html/themes
      - {{RUTA_DATOS}}/prestashop_translations:/var/www/html/translations
    networks:
      - {{EMPRESA}}_net

networks:
  {{EMPRESA}}_net:
    external: true
