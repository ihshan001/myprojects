//
//  CheckboxItem.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-07.
//
import SwiftUI

struct CheckboxItem: Identifiable {
    let id = UUID()
    let key: String
    let label: String
    let icon: Image?


    init(key: String, label: String, icon: Image? = nil) {
        self.key = key
        self.label = label
        self.icon = icon
    }
}
