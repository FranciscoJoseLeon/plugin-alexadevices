#!/bin/bash
# Script de instalación de dependencias para el plugin alexadevices de Jeedom

PROGRESS_FILE=/tmp/jeedom_install_in_progress_alexadevices
if [ -f "$PROGRESS_FILE" ]; then
    echo "Instalación de dependencias ya en curso"
    exit 0
fi

touch "$PROGRESS_FILE"
echo 0 > "$PROGRESS_FILE"

BASEDIR=$(dirname "$0")
cd "$BASEDIR" || exit 1

echo "=========================================================="
echo "Iniciando instalación de dependencias para Alexa Devices..."
echo "=========================================================="

echo 10 > "$PROGRESS_FILE"
apt-get update

echo 30 > "$PROGRESS_FILE"
apt-get install -y python3 python3-pip python3-venv python3-dev libffi-dev libssl-dev git ca-certificates curl

echo 50 > "$PROGRESS_FILE"
mkdir -p demond/venv
mkdir -p data/storage

# Comprobar versión de Python del sistema
SYS_PY_VER=$(python3 -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}')" 2>/dev/null || echo "0.0")
echo "Python detectado en el sistema: $SYS_PY_VER"

# aioamazondevices requiere Python >= 3.12. Si el sistema tiene Python < 3.12 (como Debian 12 con 3.11), usamos uv para aprovisionar Python 3.13 aislado
NEED_UV=0
if python3 -c "import sys; sys.exit(0 if sys.version_info >= (3, 12) else 1)" 2>/dev/null; then
    echo "El sistema dispone de Python >= 3.12."
else
    echo "El sistema tiene Python < 3.12. Se utilizará uv para aprovisionar Python 3.13 aislado..."
    NEED_UV=1
fi

echo 70 > "$PROGRESS_FILE"
if [ "$NEED_UV" -eq 1 ]; then
    export UV_PYTHON_INSTALL_DIR=/opt/uv-python
    mkdir -p /opt/uv-python
    if ! command -v uv &> /dev/null; then
        echo "Instalando uv standalone..."
        curl -LsSf https://astral.sh/uv/install.sh | env CARGO_HOME=/root/.cargo env UV_INSTALL_DIR=/usr/local/bin sh
    fi
    echo "Aprovisionando entorno virtual Python 3.13 con uv en /opt/uv-python..."
    uv python install 3.13
    chmod -R 755 /opt/uv-python
    uv venv --clear demond/venv --python 3.13
    echo 85 > "$PROGRESS_FILE"
    echo "Instalando dependencias de requirements.txt con uv..."
    uv pip install --python demond/venv/bin/python -r requirements.txt
else
    if [ ! -f demond/venv/bin/activate ]; then
        echo "Creando entorno virtual Python 3 del sistema..."
        python3 -m venv demond/venv
    fi
    echo 85 > "$PROGRESS_FILE"
    source demond/venv/bin/activate
    pip install --upgrade pip setuptools wheel
    pip install -r requirements.txt
fi

echo 95 > "$PROGRESS_FILE"
chown -R www-data:www-data "$BASEDIR"
chmod -R 775 "$BASEDIR/data"
chmod +x "$BASEDIR/demond/venv/bin/"* 2>/dev/null || true

echo 100 > "$PROGRESS_FILE"
rm -f "$PROGRESS_FILE"

echo "=========================================================="
echo "Dependencias de Alexa Devices instaladas con éxito!"
echo "=========================================================="
