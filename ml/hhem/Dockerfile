FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

# Pré-télécharge le modèle (trust_remote_code) pour éviter la latence au 1er appel.
ARG HHEM_MODEL=vectara/hallucination_evaluation_model
ENV HHEM_MODEL=${HHEM_MODEL}
RUN python -c "from transformers import AutoModelForSequenceClassification; AutoModelForSequenceClassification.from_pretrained('${HHEM_MODEL}', trust_remote_code=True)"

EXPOSE 8002
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8002"]
