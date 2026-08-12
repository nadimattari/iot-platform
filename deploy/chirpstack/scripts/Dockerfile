FROM python:3.13-alpine
RUN apk add --no-cache gcc musl-dev libffi-dev python3-dev \
 && pip install --no-cache-dir paho-mqtt pycryptodome
COPY lorasim.py /usr/local/bin/lorasim
