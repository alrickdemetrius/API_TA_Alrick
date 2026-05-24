from flask import Flask, request, jsonify
from flask_cors import CORS
import joblib
import numpy as np
import os
from datetime import datetime

app = Flask(__name__)
CORS(app)

MODEL_PATH = os.path.join('models', 'model_abrsm_ujian.pkl')

print("=" * 60)
print("ABRSM PRACTICAL PREDICTION API - Starting...")
print("=" * 60)

try:
    print(f"Loading model from: {MODEL_PATH}")
    model_package = joblib.load(MODEL_PATH)
    model                 = model_package['model']
    feature_names         = model_package['feature_names']
    reverse_label_mapping = model_package['reverse_label_mapping']
    metadata              = model_package['metadata']
    print("Model loaded successfully!")
    print(f"  Features : {feature_names}")
    print(f"  Labels   : {list(reverse_label_mapping.values())}")
    print(f"  Accuracy : {metadata.get('test_accuracy', 'N/A')}")
    print(f"  Trained  : {model_package.get('training_date', 'Unknown')}")
    print("=" * 60)
    MODEL_LOADED = True
except FileNotFoundError:
    print(f"ERROR: Model file not found at {MODEL_PATH}")
    print("Run train_abrsm_model.py terlebih dahulu.")
    MODEL_LOADED = False
except Exception as e:
    print(f"ERROR loading model: {str(e)}")
    MODEL_LOADED = False


@app.route('/predict', methods=['POST'])
def predict():
    if not MODEL_LOADED:
        return jsonify({'success': False, 'message': 'Model belum dimuat.'}), 500

    try:
        data = request.json

        for field in ['murid_id', 'goal_id', 'grade', 'nilai']:
            if field not in data:
                return jsonify({'success': False, 'message': f'Field wajib tidak ada: {field}'}), 400

        nilai = data['nilai']
        grade = int(data['grade'])

        required_cats = ['lagu', 'scales', 'sight', 'aural']
        for cat in required_cats:
            if cat not in nilai:
                return jsonify({'success': False, 'message': f'Kategori nilai tidak ada: {cat}'}), 400

        validations = [
            ('lagu',   nilai['lagu'],   0, 90),
            ('scales', nilai['scales'], 0, 21),
            ('sight',  nilai['sight'],  0, 21),
            ('aural',  nilai['aural'],  0, 18),
        ]
        for name, val, lo, hi in validations:
            v = float(val)
            if v < lo or v > hi:
                return jsonify({
                    'success': False,
                    'message': f'Nilai {name} harus antara {lo}-{hi}, diterima: {v}'
                }), 400

        if grade < 1 or grade > 8:
            return jsonify({'success': False, 'message': 'Grade harus 1-8'}), 400

        lagu_avg = float(nilai['lagu']) / 3.0

        features = np.array([[
            grade,
            lagu_avg,
            float(nilai['scales']),
            float(nilai['sight']),
            float(nilai['aural'])
        ]])

        prediction    = model.predict(features)[0]
        probabilities = model.predict_proba(features)[0]
        result        = reverse_label_mapping[prediction]
        confidence    = float(probabilities[prediction])

        total_score = float(nilai['lagu']) + float(nilai['scales']) + \
                      float(nilai['sight']) + float(nilai['aural'])

        response = {
            'success': True,
            'prediction': {
                'result':                result,
                'confidence':            round(confidence, 4),
                'confidence_percentage': round(confidence * 100, 2),
                'probabilities': {
                    'Fail':        round(float(probabilities[0]), 4),
                    'Pass':        round(float(probabilities[1]), 4),
                    'Merit':       round(float(probabilities[2]), 4),
                    'Distinction': round(float(probabilities[3]), 4)
                }
            },
            'input_data': {
                'murid_id':    data['murid_id'],
                'goal_id':     data['goal_id'],
                'grade':       grade,
                'nilai':       nilai,
                'lagu_avg':    round(lagu_avg, 2),
                'total_score': round(total_score, 2)
            },
            'model_info': {'training_date': model_package.get('training_date', 'Unknown')},
            'timestamp': datetime.now().isoformat()
        }

        print(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] Murid={data['murid_id']} "
              f"Goal={data['goal_id']} Grade={grade} "
              f"LaguAvg={lagu_avg:.2f} Total={total_score:.1f}/150 "
              f"→ {result} ({confidence*100:.1f}%)")

        return jsonify(response), 200

    except ValueError as e:
        return jsonify({'success': False, 'message': f'Nilai tidak valid: {str(e)}'}), 400
    except Exception as e:
        import traceback; traceback.print_exc()
        return jsonify({'success': False, 'message': f'Prediction error: {str(e)}'}), 500


@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'success':      True,
        'status':       'healthy' if MODEL_LOADED else 'model_not_loaded',
        'model_loaded': MODEL_LOADED,
        'timestamp':    datetime.now().isoformat()
    }), 200


@app.route('/model-info', methods=['GET'])
def model_info():
    if not MODEL_LOADED:
        return jsonify({'success': False, 'message': 'Model belum dimuat'}), 500
    return jsonify({
        'success': True,
        'model_info': {
            'features':      feature_names,
            'labels':        list(reverse_label_mapping.values()),
            'test_accuracy': metadata.get('test_accuracy', 'N/A'),
            'cv_score':      metadata.get('cv_mean', 'N/A'),
            'training_date': model_package.get('training_date', 'Unknown'),
            'n_samples':     metadata.get('n_samples', 'N/A'),
            'nilai_ranges': {
                'lagu':   '0-90 (avg per lagu *3)',
                'scales': '0-21', 'sight': '0-21', 'aural': '0-18',
                'total':  '0-150'
            }
        }
    }), 200


if __name__ == '__main__':
    print("\nStarting ABRSM Practical Prediction Server...")
    print("URL  : http://localhost:5000")
    print("Endpoints:")
    print("  POST /predict     - Jalankan prediksi")
    print("  GET  /health      - Cek status server")
    print("  GET  /model-info  - Info model")
    print()
    app.run(host='0.0.0.0', port=5000, debug=True, use_reloader=False)