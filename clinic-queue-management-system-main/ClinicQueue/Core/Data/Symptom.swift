//
//  Symptom.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//
import Foundation

struct Symptom: Identifiable {
    let id = UUID()
    let key: String
    let label: String
    let specialty: String
}

struct SymptomData {

    static let symptoms: [Symptom] = [

        Symptom(key: "fever", label: "Fever", specialty: "General"),
        Symptom(key: "headache", label: "Headache", specialty: "Neurology"),
        Symptom(key: "cough", label: "Cough / Cold", specialty: "Pulmonology"),
        Symptom(key: "stomach", label: "Stomach Pain", specialty: "Gastroenterology"),
        Symptom(key: "skin", label: "Skin Rash / Allergy", specialty: "Dermatology"),
        Symptom(key: "body", label: "Body Pain / Joint Pain", specialty: "Orthopedics"),
        Symptom(key: "vomiting", label: "Vomiting / Diarrhea", specialty: "Gastroenterology"),
        Symptom(key: "ear", label: "Ear Pain", specialty: "ENT"),
        Symptom(key: "eye", label: "Eye Problem", specialty: "Ophthalmology"),
        Symptom(key: "child", label: "Child Illness", specialty: "Pediatrics"),
        Symptom(key: "chest", label: "Chest Pain", specialty: "Cardiology"),
        Symptom(key: "general", label: "General Check-up", specialty: "General")

    ]

}
