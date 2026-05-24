import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split, cross_val_score
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import classification_report, confusion_matrix, accuracy_score
import joblib
import os
from datetime import datetime

CONFIG = {
    'dataset_path':      'competition_training_data.csv',
    'model_output_path': 'models/model_competition.pkl',
    'test_size':         0.2,
    'random_state':      42,
    'cv_folds':          5,

    'n_estimators':      300,
    'max_depth':         None,
    'min_samples_split': 2,
    'min_samples_leaf':  1,
    'max_features':      'sqrt',
}

FEATURE_COLS = [
    'tempo_control',
    'accuracy_cleanliness',
    'hand_coordination',
    'dynamics_articulation',
    'expression_emotion',
    'phrasing',
    'stage_presence'
]

LABEL_MAPPING = {
    'Not Ready':   0,
    'Developing':  1,
    'Ready':       2,
    'Competitive': 3
}

REVERSE_LABEL_MAPPING = {v: k for k, v in LABEL_MAPPING.items()}

def load_dataset(filepath):
    print(f"📂 Loading dataset from: {filepath}")
    if not os.path.exists(filepath):
        raise FileNotFoundError(f"Dataset tidak ditemukan: {filepath}")

    df = pd.read_csv(filepath)
    print(f"Dataset loaded — {len(df)} samples, {list(df.columns)}")
    return df


def validate_dataset(df):
    print("\nValidating dataset...")

    required = FEATURE_COLS + ['label']
    missing  = [c for c in required if c not in df.columns]
    if missing:
        raise ValueError(f"Kolom tidak ditemukan: {missing}")

    if df[required].isnull().any().any():
        print(f"Ada nilai kosong")

    for col in FEATURE_COLS:
        invalid = df[~df[col].isin([0, 1, 2, 3])][col].unique()
        if len(invalid) > 0:
            raise ValueError(f"Nilai tidak valid di kolom {col}: {invalid}")

    valid_labels = list(LABEL_MAPPING.keys())
    invalid_labels = df[~df['label'].isin(valid_labels)]['label'].unique()
    if len(invalid_labels) > 0:
        raise ValueError(f"Label tidak valid: {invalid_labels}")

    print("Validasi dataset berhasil")
    return True


def prepare_features_and_labels(df):
    print("\nPreparing features and labels...")

    X = df[FEATURE_COLS].values
    y = df['label'].map(LABEL_MAPPING).values

    print(f"Feature shape  : {X.shape}")
    print(f"Feature columns: {FEATURE_COLS}")
    print(f"Label encoding : {LABEL_MAPPING}")
    print(f"\n  Distribusi label:")
    for label, code in LABEL_MAPPING.items():
        count = np.sum(y == code)
        pct   = count / len(y) * 100
        print(f"    {label:>12} ({code}): {count} ({pct:.1f}%)")

    return X, y


def train_model(X_train, y_train):
    print("\nTraining Random Forest...")
    print(f"  n_estimators     : {CONFIG['n_estimators']}")
    print(f"  max_depth        : {CONFIG['max_depth']}")
    print(f"  min_samples_split: {CONFIG['min_samples_split']}")
    print(f"  min_samples_leaf : {CONFIG['min_samples_leaf']}")

    model = RandomForestClassifier(
        n_estimators     = CONFIG['n_estimators'],
        max_depth        = CONFIG['max_depth'],
        min_samples_split= CONFIG['min_samples_split'],
        min_samples_leaf = CONFIG['min_samples_leaf'],
        max_features     = CONFIG['max_features'],
        random_state     = CONFIG['random_state'],
        n_jobs           = -1,
        verbose          = 0
    )
    model.fit(X_train, y_train)
    print("Training selesai")
    return model


def evaluate_model(model, X_train, y_train, X_test, y_test):
    print("\nEvaluating model...")

    y_train_pred = model.predict(X_train)
    y_test_pred  = model.predict(X_test)

    train_acc = accuracy_score(y_train, y_train_pred)
    test_acc  = accuracy_score(y_test,  y_test_pred)
    cv_scores = cross_val_score(model, X_train, y_train, cv=CONFIG['cv_folds'])

    print(f"\nTraining Accuracy      : {train_acc:.4f} ({train_acc*100:.2f}%)")
    print(f"Test Accuracy          : {test_acc:.4f}  ({test_acc*100:.2f}%)")
    print(f"CV Score (5-fold)      : {cv_scores.mean():.4f} ± {cv_scores.std():.4f}")

    label_names = [REVERSE_LABEL_MAPPING[i] for i in range(4)]

    print("\nConfusion Matrix (Test Set):")
    cm = confusion_matrix(y_test, y_test_pred)
    print(f"\n{'':>14}", end='')
    for name in label_names:
        print(f"{name:>13}", end='')
    print()
    for i, name in enumerate(label_names):
        print(f"{name:>14}", end='')
        for j in range(4):
            print(f"{cm[i][j]:>13}", end='')
        print()

    print("\nClassification Report:")
    print(classification_report(y_test, y_test_pred, target_names=label_names))

    print("Feature Importance:")
    fi = pd.DataFrame({
        'feature':    FEATURE_COLS,
        'importance': model.feature_importances_
    }).sort_values('importance', ascending=False)
    for _, row in fi.iterrows():
        bar = '█' * int(row['importance'] * 50)
        print(f"  {row['feature']:>24}: {row['importance']:.4f}  {bar}")

    return {
        'train_accuracy': train_acc,
        'test_accuracy':  test_acc,
        'cv_mean':        cv_scores.mean(),
        'cv_std':         cv_scores.std(),
        'confusion_matrix': cm,
        'feature_importance': fi
    }


def save_model(model, metrics, n_samples):
    output_path = CONFIG['model_output_path']
    print(f"\nSaving model to: {output_path}")

    os.makedirs(os.path.dirname(output_path), exist_ok=True)

    model_package = {
        'model':                model,
        'feature_names':        FEATURE_COLS,
        'label_mapping':        LABEL_MAPPING,
        'reverse_label_mapping': REVERSE_LABEL_MAPPING,
        'training_date':        datetime.now().isoformat(),
        'metadata': {
            'n_samples':      n_samples,
            'n_features':     len(FEATURE_COLS),
            'test_accuracy':  metrics['test_accuracy'],
            'train_accuracy': metrics['train_accuracy'],
            'cv_mean':        metrics['cv_mean'],
            'cv_std':         metrics['cv_std'],
            'config':         CONFIG
        }
    }

    joblib.dump(model_package, output_path, compress=3)

    version_file = output_path.replace('.pkl', '_version.txt')
    with open(version_file, 'w') as f:
        f.write(f"Model trained  : {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"Training samples: {n_samples}\n")
        f.write(f"Test accuracy  : {metrics['test_accuracy']:.4f}\n")
        f.write(f"CV score       : {metrics['cv_mean']:.4f} ± {metrics['cv_std']:.4f}\n")
        f.write(f"Features       : {', '.join(FEATURE_COLS)}\n")
        f.write(f"Labels         : Not Ready, Developing, Ready, Competitive\n")

    print(f"Model saved: {output_path}")
    print(f"Version info: {version_file}")


def test_prediction(model):
    print("\nTesting sample predictions\n")

    test_cases = [
        {
            'name': 'Not Ready — mayoritas Poor/Fair',
            'features': [0, 1, 0, 1, 0, 0, 1],
            'expected': 'Not Ready'
        },
        {
            'name': 'Developing — campuran Fair/Good tidak konsisten',
            'features': [1, 2, 1, 2, 1, 1, 2],
            'expected': 'Developing'
        },
        {
            'name': 'Ready — mayoritas Good',
            'features': [2, 2, 3, 2, 2, 2, 2],
            'expected': 'Ready'
        },
        {
            'name': 'Competitive — mayoritas Excellent',
            'features': [3, 3, 3, 3, 2, 3, 3],
            'expected': 'Competitive'
        }
    ]

    for tc in test_cases:
        features = np.array([tc['features']])
        pred     = model.predict(features)[0]
        proba    = model.predict_proba(features)[0]
        result   = REVERSE_LABEL_MAPPING[pred]
        conf     = float(proba[pred]) * 100

        status = 'Success' if result == tc['expected'] else 'Warning'
        print(f"{status} {tc['name']}")
        print(f"   Input     : {dict(zip(FEATURE_COLS, tc['features']))}")
        print(f"   Prediction: {result} ({conf:.1f}%) — Expected: {tc['expected']}")
        print(f"   Proba     : { {REVERSE_LABEL_MAPPING[i]: f'{proba[i]*100:.1f}%' for i in range(4)} }")
        print()


# ========================================
# MAIN
# ========================================

def main():
    print("PIANO COMPETITION READINESS PREDICTION - MODEL TRAINING")
    print()

    try:
        df = load_dataset(CONFIG['dataset_path'])
        validate_dataset(df)
        X, y = prepare_features_and_labels(df)

        print(f"\nSplitting dataset (test_size={CONFIG['test_size']})...")
        X_train, X_test, y_train, y_test = train_test_split(
            X, y,
            test_size    = CONFIG['test_size'],
            random_state = CONFIG['random_state'],
            stratify     = y
        )
        print(f"Training set: {X_train.shape[0]} samples")
        print(f"Test set    : {X_test.shape[0]} samples")

        model   = train_model(X_train, y_train)
        metrics = evaluate_model(model, X_train, y_train, X_test, y_test)

        save_model(model, metrics, len(df))
        test_prediction(model)

        print("TRAINING COMPLETED SUCCESSFULLY!")
        print(f"\nModel    : {CONFIG['model_output_path']}")
        print(f"Accuracy : {metrics['test_accuracy']*100:.2f}%")
        print(f"CV Score : {metrics['cv_mean']*100:.2f}% ± {metrics['cv_std']*100:.2f}%")

    except Exception as e:
        print(f"\nERROR: {str(e)}")
        import traceback; traceback.print_exc()
        return 1

    return 0


if __name__ == '__main__':
    exit(main())