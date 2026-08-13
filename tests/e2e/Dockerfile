# syntax=docker/dockerfile:1

# E2E runner (Task 24): mock device drivers + assertions against the full
# stack started by deploy/docker-compose.test.yml. The container exits 0 on
# success and non-zero on failure so `docker compose up --exit-code-from e2e`
# reflects the suite result in the shell and in CI.
FROM python:3.12-alpine
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1
WORKDIR /app
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY run_e2e.py e2e_lib.py ./
COPY drivers ./drivers
CMD ["python", "-u", "run_e2e.py"]
