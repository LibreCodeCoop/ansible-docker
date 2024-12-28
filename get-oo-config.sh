#!/bin/bash
# Lista as configurações do Onlyoffice

configs=(
    "demo"
    "DocumentServerUrl"
    "documentserverInternal"
    "StorageUrl"
    "secret"
    "defFormats"
    "editFormats"
    "sameTab"
    "preview"
    "advanced"
    "cronChecker"
    "versionHistory"
    "protection"
    "customizationChat"
    "customizationCompactHeader"
    "customizationFeedback"
    "customizationForcesave"
    "customizationHelp"
    "customizationToolbarNoTabs"
    "customizationReviewDisplay"
    "customizationTheme"
    "groups"
    "verify_peer_off"
    "jwt_secret"
    "jwt_header"
    "jwt_leeway"
    "settings_error"
    "limit_thumb_size"
    "permissions_modifyFilter"
    "customization_customer"
    "customization_loaderLogo"
    "customization_loaderName"
    "customization_logo"
    "customization_zoom"
    "customization_autosave"
    "customization_goback"
    "customization_macros"
    "customization_plugins"
    "editors_check_interval"
)

CONTAINER_NAME="nextcloud-docker-app-1"
> ./out-oo.txt

for config in "${configs[@]}"; do
    echo "Variável $config está configurado como..." 
    #out=`docker exec -u www-data "$CONTAINER_NAME" php occ config:app:get onlyoffice "$config"`
    echo "$config=`docker exec -u www-data "$CONTAINER_NAME" php occ config:app:get onlyoffice "$config"`" >> ./out-oo.txt
done
