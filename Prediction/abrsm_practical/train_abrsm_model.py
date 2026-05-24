import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split, cross_val_score
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import classification_report, confusion_matrix, accuracy_score
import joblib
import os
from datetime import datetime

# ── Perubahan dari versi sebelumnya ──────────────────────────────────────────
# Fitur lagu_1, lagu_2, lagu_3 (3 kolom terpisah) digabung menjadi 1 kolom 'lagu'
# (rata-rata nilai ketiga lagu). Ini menyesuaikan sistem penilaian baru di mana
# rubrik Lagu memiliki sub-kategori bebas (tidak selalu 3), dan nilainya
# dirata-rata sebelum dikirim ke model.
# Feature set baru: ['grade', 'lagu', 'scales', 'sight', 'aural']  (5 fitur)
# ─────────────────────────────────────────────────────────────────────────────

CONFIG = {
    'dataset_path':      'abrsm_training_data.csv',
    'model_output_path': 'models/model_abrsm_ujian.pkl',
    'test_size':         0.2,
    'random_state':      42,
    'cv_folds':          5,
    'n_estimators':      200,
    'max_depth':         15,
    'min_samples_split': 5,
    'min_samples_leaf':  2,
    'max_features':      'sqrt',
}

FEATURE_COLS  = ['grade', 'lagu', 'scales', 'sight', 'aural']
LABEL_MAPPING = {'Fail': 0, 'Pass': 1, 'Merit': 2, 'Distinction': 3}
REVERSE_LABEL = {v: k for k, v in LABEL_MAPPING.items()}

# ABRSM scoring reference (Grade 1-8):
#   Lagu (3 pieces) @ max 30 each = 90  → rata-rata max 30
#   Scales          @ max 21
#   Sight Reading   @ max 21
#   Aural           @ max 18
#   Total max                     = 150
#   Distinction: 130-150 | Merit: 120-129 | Pass: 100-119 | Fail: <100


def load_dataset(filepath):
    if not os.path.exists(filepath):
        raise FileNotFoundError(f"Dataset tidak ditemukan: {filepath}")

    df = pd.read_csv(filepath)
    print(f"  - Total samples : {len(df)}")
    print(f"  - Kolom         : {list(df.columns)}")
    return df


def validate_dataset(df):
    missing = [c for c in FEATURE_COLS + ['result'] if c not in df.columns]
    if missing:
        raise ValueError(f"Kolom tidak ditemukan: {missing}")

    if df[FEATURE_COLS + ['result']].isnull().any().any():
        print("⚠ Ada nilai kosong:", df.isnull().sum()[df.isnull().sum() > 0].to_dict())

    if not (1 <= df['grade'].min() and df['grade'].max() <= 8):
        raise ValueError(f"Grade tidak valid: {df['grade'].min()}-{df['grade'].max()}, expected 1-8")

    invalid = df[~df['result'].isin(LABEL_MAPPING.keys())]['result'].unique()
    if len(invalid):
        raise ValueError(f"Label tidak valid: {invalid}")

    # Validasi range nilai sesuai ABRSM
    assert df['lagu'].between(5, 30).all(),    "lagu harus 5-30"
    assert df['scales'].between(4, 21).all(),  "scales harus 4-21"
    assert df['sight'].between(2, 21).all(),   "sight harus 2-21"
    assert df['aural'].between(3, 18).all(),   "aural harus 3-18"

    print("✓ Validasi dataset berhasil")
    return True


def prepare_features_and_labels(df):
    X = df[FEATURE_COLS].values
    y = df['result'].map(LABEL_MAPPING).values

    print(f"  - Feature shape  : {X.shape}")
    print(f"  - Feature columns: {FEATURE_COLS}")
    print(f"  - Label mapping  : {LABEL_MAPPING}")
    print(f"  - Distribusi label:")
    for label, code in LABEL_MAPPING.items():
        count = int(np.sum(y == code))
        print(f"    {label:12s} ({code}): {count} ({count/len(y)*100:.1f}%)")

    return X, y


def train_model(X_train, y_train):
    print(f"  - n_estimators      : {CONFIG['n_estimators']}")
    print(f"  - max_depth         : {CONFIG['max_depth']}")
    print(f"  - min_samples_split : {CONFIG['min_samples_split']}")
    print(f"  - min_samples_leaf  : {CONFIG['min_samples_leaf']}")

    model = RandomForestClassifier(
        n_estimators     = CONFIG['n_estimators'],
        max_depth        = CONFIG['max_depth'],
        min_samples_split= CONFIG['min_samples_split'],
        min_samples_leaf = CONFIG['min_samples_leaf'],
        max_features     = CONFIG['max_features'],
        random_state     = CONFIG['random_state'],
        n_jobs           = -1,
    )
    model.fit(X_train, y_train)
    print("  ✓ Training selesai")
    return model


def evaluate_model(model, X_train, y_train, X_test, y_test):
    y_train_pred = model.predict(X_train)
    y_test_pred  = model.predict(X_test)

    train_acc = accuracy_score(y_train, y_train_pred)
    test_acc  = accuracy_score(y_test,  y_test_pred)
    cv_scores = cross_val_score(model, X_train, y_train, cv=CONFIG['cv_folds'])

    print(f"\nTraining Accuracy      : {train_acc:.4f} ({train_acc*100:.2f}%)")
    print(f"Test Accuracy          : {test_acc:.4f}  ({test_acc*100:.2f}%)")
    print(f"Cross-Validation (5-fold): {cv_scores.mean():.4f} ± {cv_scores.std():.4f}")

    label_names = [REVERSE_LABEL[i] for i in range(4)]
    cm = confusion_matrix(y_test, y_test_pred)
    print("\nConfusion Matrix (Test):")
    print(f"{'':12s}  " + "  ".join(f"{n:>12s}" for n in label_names))
    for i, lbl in enumerate(label_names):
        print(f"{lbl:12s}  " + "  ".join(f"{cm[i][j]:>12d}" for j in range(4)))

    print("\nClassification Report:")
    print(classification_report(y_test, y_test_pred, target_names=label_names))

    fi = pd.DataFrame({'feature': FEATURE_COLS, 'importance': model.feature_importances_})
    fi = fi.sort_values('importance', ascending=False)
    print("Feature Importance:")
    for _, row in fi.iterrows():
        bar = '█' * int(row['importance'] * 40)
        print(f"  {row['feature']:>8}: {row['importance']:.4f}  {bar}")

    return {
        'train_accuracy': train_acc,
        'test_accuracy':  test_acc,
        'cv_mean':        cv_scores.mean(),
        'cv_std':         cv_scores.std(),
        'confusion_matrix': cm,
    }


def save_model(model, output_path, metadata):
    os.makedirs(os.path.dirname(output_path), exist_ok=True)

    model_package = {
        'model':                 model,
        'metadata':              metadata,
        'training_date':         datetime.now().isoformat(),
        'feature_names':         FEATURE_COLS,
        'label_mapping':         LABEL_MAPPING,
        'reverse_label_mapping': REVERSE_LABEL,
    }
    joblib.dump(model_package, output_path, compress=3)

    version_file = output_path.replace('.pkl', '_version.txt')
    with open(version_file, 'w') as f:
        f.write(f"Model trained    : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"Feature columns  : {FEATURE_COLS}\n")
        f.write(f"Training samples : {metadata['n_samples']}\n")
        f.write(f"Test accuracy    : {metadata['test_accuracy']:.4f}\n")
        f.write(f"CV score         : {metadata['cv_mean']:.4f} ± {metadata['cv_std']:.4f}\n")

    print(f"✓ Model disimpan ke: {output_path}")
    print(f"  Version info    : {version_file}")


def test_prediction(model):
    """Uji prediksi dengan beberapa contoh kasus nyata"""
    test_cases = [
        {
            'name': 'Grade 1 - Skor Rendah (Fail)',
            'features': [1, 12.0, 9, 8, 7],   # total sekitar 70
            'expected': 'Fail'
        },
        {
            'name': 'Grade 3 - Batas Pass',
            'features': [3, 22.3, 15, 14, 12],  # total sekitar 107
            'expected': 'Pass'
        },
        {
            'name': 'Grade 5 - Merit',
            'features': [5, 25.0, 17, 16, 14],  # total sekitar 121
            'expected': 'Merit'
        },
        {
            'name': 'Grade 8 - Distinction',
            'features': [8, 29.0, 20, 19, 17],  # total sekitar 143
            'expected': 'Distinction'
        },
    ]

    print("\nTest Predictions:")
    for tc in test_cases:
        feat = np.array([tc['features']])
        pred = model.predict(feat)[0]
        prob = model.predict_proba(feat)[0]
        result = REVERSE_LABEL[pred]
        g, lagu, sc, si, au = tc['features']
        approx_total = lagu * 3 + sc + si + au
        print(f"\n  {tc['name']}")
        print(f"  Input : grade={g}, lagu_avg={lagu}, scales={sc}, sight={si}, aural={au}")
        print(f"  Total approx: {approx_total:.0f}/150")
        print(f"  Prediksi: {result} (Expected: {tc['expected']})")
        for label, code in LABEL_MAPPING.items():
            print(f"    {label:12s}: {prob[code]*100:.1f}%")


def main():
    print("=" * 60)
    print(" ABRSM MODEL TRAINING (v2 - single lagu feature)")
    print("=" * 60)

    try:
        print("\n[1] Load Dataset")
        df = load_dataset(CONFIG['dataset_path'])

        print("\n[2] Validasi Dataset")
        validate_dataset(df)

        print("\n[3] Prepare Features")
        X, y = prepare_features_and_labels(df)

        print(f"\n[4] Split Dataset (test_size={CONFIG['test_size']})")
        X_train, X_test, y_train, y_test = train_test_split(
            X, y,
            test_size    = CONFIG['test_size'],
            random_state = CONFIG['random_state'],
            stratify     = y
        )
        print(f"  Training : {X_train.shape[0]} samples")
        print(f"  Test     : {X_test.shape[0]} samples")

        print("\n[5] Training Model")
        model = train_model(X_train, y_train)

        print("\n[6] Evaluasi Model")
        metrics = evaluate_model(model, X_train, y_train, X_test, y_test)

        print("\n[7] Simpan Model")
        metadata = {
            'n_samples':      len(df),
            'n_features':     X.shape[1],
            'feature_names':  FEATURE_COLS,
            'test_accuracy':  metrics['test_accuracy'],
            'train_accuracy': metrics['train_accuracy'],
            'cv_mean':        metrics['cv_mean'],
            'cv_std':         metrics['cv_std'],
            'config':         CONFIG,
        }
        save_model(model, CONFIG['model_output_path'], metadata)

        print("\n[8] Test Prediksi")
        test_prediction(model)

        print("\n" + "=" * 60)
        print(" TRAINING SELESAI")
        print(f" Test Accuracy : {metrics['test_accuracy']*100:.2f}%")
        print(f" CV Score      : {metrics['cv_mean']*100:.2f}% ± {metrics['cv_std']*100:.2f}%")
        print(f" Model         : {CONFIG['model_output_path']}")
        print("=" * 60)

    except Exception as e:
        print(f"\nERROR: {e}")
        import traceback
        traceback.print_exc()
        return 1

    return 0


if __name__ == '__main__':
    exit(main())