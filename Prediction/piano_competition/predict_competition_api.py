from flask import Flask, request, jsonify
from flask_cors import CORS
import joblib
import numpy as np
import os
from datetime import datetime

app = Flask(__name__)
CORS(app)

MODEL_PATH = os.path.join('models', 'model_competition.pkl')

FEATURE_COLS = [
    'tempo_control',
    'accuracy_cleanliness',
    'hand_coordination',
    'dynamics_articulation',
    'expression_emotion',
    'phrasing',
    'stage_presence'
]

print("=" * 60)
print("PIANO COMPETITION PREDICTION API - Starting...")
print("=" * 60)

try:
    print(f"Loading model from: {MODEL_PATH}")
    model_package = joblib.load(MODEL_PATH)

    model                 = model_package['model']
    feature_names         = model_package['feature_names']
    label_mapping         = model_package['label_mapping']
    reverse_label_mapping = model_package['reverse_label_mapping']
    metadata              = model_package['metadata']

    print("Model loaded successfully!")
    print(f"  Features : {feature_names}")
    print(f"  Labels   : {list(reverse_label_mapping.values())}")
    print(f"  Accuracy : {metadata.get('test_accuracy', 'N/A'):.4f}")
    print(f"  Trained  : {model_package.get('training_date', 'Unknown')}")
    print("=" * 60)
    MODEL_LOADED = True

except FileNotFoundError:
    print(f"ERROR: Model tidak ditemukan di {MODEL_PATH}")
    print("Jalankan train_competition_model.py terlebih dahulu.")
    print("=" * 60)
    MODEL_LOADED = False

except Exception as e:
    print(f"ERROR loading model: {str(e)}")
    print("=" * 60)
    MODEL_LOADED = False


# ── Prediction endpoint ───────────────────────────────────────────────────────

@app.route('/predict', methods=['POST'])
def predict():
    if not MODEL_LOADED:
        return jsonify({
            'success': False,
            'message': 'Model belum dimuat. Jalankan train_competition_model.py terlebih dahulu.'
        }), 500

    try:
        data = request.json

        # Validasi field wajib
        for field in ['murid_id', 'goal_id', 'nilai']:
            if field not in data:
                return jsonify({
                    'success': False,
                    'message': f'Field wajib tidak ada: {field}'
                }), 400

        nilai = data['nilai']

        # Validasi semua rubrik ada
        for rubrik in FEATURE_COLS:
            if rubrik not in nilai:
                return jsonify({
                    'success': False,
                    'message': f'Rubrik tidak ada: {rubrik}'
                }), 400

        # Validasi nilai rubrik 0.0-3.0 (float, hasil interpolasi)
        for rubrik in FEATURE_COLS:
            val = nilai[rubrik]
            try:
                v = float(val)
            except (TypeError, ValueError):
                return jsonify({
                    'success': False,
                    'message': f'Nilai {rubrik} harus angka, diterima: {val}'
                }), 400
            if v < 0.0 or v > 3.0:
                return jsonify({
                    'success': False,
                    'message': f'Nilai {rubrik} harus antara 0.0-3.0, diterima: {v}'
                }), 400

        # Susun fitur sesuai urutan training — pakai float untuk terima desimal
        features = np.array([[
            float(nilai['tempo_control']),
            float(nilai['accuracy_cleanliness']),
            float(nilai['hand_coordination']),
            float(nilai['dynamics_articulation']),
            float(nilai['expression_emotion']),
            float(nilai['phrasing']),
            float(nilai['stage_presence'])
        ]])

        # Prediksi
        prediction    = model.predict(features)[0]
        probabilities = model.predict_proba(features)[0]

        result     = reverse_label_mapping[prediction]
        confidence = float(probabilities[prediction])

        response = {
            'success': True,
            'prediction': {
                'result':                result,
                'confidence':            round(confidence, 4),
                'confidence_percentage': round(confidence * 100, 2),
                'probabilities': {
                    'Not Ready':   round(float(probabilities[0]), 4),
                    'Developing':  round(float(probabilities[1]), 4),
                    'Ready':       round(float(probabilities[2]), 4),
                    'Competitive': round(float(probabilities[3]), 4)
                }
            },
            'input_data': {
                'murid_id': data['murid_id'],
                'goal_id':  data['goal_id'],
                'nilai':    {k: float(v) for k, v in nilai.items() if k in FEATURE_COLS}
            },
            'model_info': {
                'training_date': model_package.get('training_date', 'Unknown')
            },
            'timestamp': datetime.now().isoformat()
        }

        print(
            f"[{datetime.now():%Y-%m-%d %H:%M:%S}] "
            f"Murid={data['murid_id']} Goal={data['goal_id']} "
            f"→ {result} ({confidence*100:.1f}%)"
        )

        return jsonify(response), 200

    except ValueError as e:
        return jsonify({'success': False, 'message': f'Nilai tidak valid: {str(e)}'}), 400
    except Exception as e:
        import traceback; traceback.print_exc()
        return jsonify({'success': False, 'message': f'Prediction error: {str(e)}'}), 500


# ── Health check ──────────────────────────────────────────────────────────────

@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'success':      True,
        'status':       'healthy' if MODEL_LOADED else 'model_not_loaded',
        'model_loaded': MODEL_LOADED,
        'timestamp':    datetime.now().isoformat()
    }), 200


# ── Model info ────────────────────────────────────────────────────────────────

@app.route('/model-info', methods=['GET'])
def model_info():
    if not MODEL_LOADED:
        return jsonify({'success': False, 'message': 'Model belum dimuat'}), 500

    return jsonify({
        'success': True,
        'model_info': {
            'features':      feature_names,
            'labels':        ['Not Ready', 'Developing', 'Ready', 'Competitive'],
            'nilai_rubrik':  'Poor=0, Fair=1, Good=2, Excellent=3',
            'test_accuracy': metadata.get('test_accuracy', 'N/A'),
            'cv_score':      metadata.get('cv_mean', 'N/A'),
            'training_date': model_package.get('training_date', 'Unknown'),
            'n_samples':     metadata.get('n_samples', 'N/A')
        }
    }), 200

if __name__ == '__main__':
    print("\nStarting Piano Competition Prediction Server...")
    print("URL  : http://localhost:5001")
    print("Endpoints:")
    print("  POST /predict     - Jalankan prediksi")
    print("  GET  /health      - Cek status server")
    print("  GET  /model-info  - Info model")
    print()
    app.run(host='0.0.0.0', port=5001, debug=True, use_reloader=False)