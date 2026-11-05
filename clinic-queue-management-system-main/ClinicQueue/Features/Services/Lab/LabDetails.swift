//
//  LabDetails.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-08.
//

import SwiftUI

struct LabDetails: View {
    let specialties: [CategoryItem] = LabCategoriesData.categories
    let labTests: [LabCardData] = LabData.labTests

    @State private var selectedTests: Set<UUID> = []
    @State private var searchText = ""
    @State private var selectedCategory: String?
    @State private var navigateToNext = false

    let initialCategory: String

    init(selectedCategory: String) {
        self.initialCategory = selectedCategory
        self._selectedCategory = State(initialValue: selectedCategory)
    }

    // Title updates when category changes
    private var pageTitle: String {
        selectedCategory ?? initialCategory
    }

    private var filteredLabTests: [LabCardData] {
        if searchText.isEmpty {
            return labTests
        } else {
            return labTests.filter { $0.title.localizedCaseInsensitiveContains(searchText) }
        }
    }

    private var selectedLabCards: [LabCardData] {
        filteredLabTests.filter { selectedTests.contains($0.id) }
    }

    private var availableLabCards: [LabCardData] {
        filteredLabTests.filter { !selectedTests.contains($0.id) }
    }

    var body: some View {
        ZStack(alignment: .bottom) {
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    HeaderSection(title: pageTitle)
                        .animation(.easeInOut(duration: 0.25), value: pageTitle)
                }
                .padding(.top, -60)

                VStack(alignment: .center) {
                    IconInputField(
                        iconName: "SearchIcon",
                        placeholder: "Find a Lab Test",
                        value: $searchText
                    )
                    .padding(.top, 10)
                    .padding(.horizontal, 10)
                }

                Text("Category")
                    .font(.app(.heading))
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.top, 32)
                    .padding(.horizontal, 10)

                // Single-select via custom Binding — only one button highlighted at a time
                CategoryGrid(
                    items: specialties,
                    selectedCategories: Binding(
                        get: {
                            selectedCategory.map { [$0] } ?? []
                        },
                        set: { newValue in
                            let current: Set<String> = selectedCategory.map { [$0] } ?? []
                            let added = newValue.subtracting(current)

                            withAnimation(.easeInOut(duration: 0.25)) {
                                if let newlyTapped = added.first {
                                    // Tap new button → deselect old, select new
                                    selectedCategory = newlyTapped
                                } else {
                                    // Tap same button → deselect
                                    selectedCategory = nil
                                }
                            }
                        }
                    )
                )
                .padding(.top, 20)

                if !selectedTests.isEmpty {
                    Text("Selected Tests")
                        .font(.app(.heading))
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(.top, 32)
                        .padding(.horizontal, 10)

                    VStack(spacing: 12) {
                        ForEach(selectedLabCards) { test in
                            BloodTestCard(
                                image: TestDataset.imageName(for: test.title),
                                title: test.title,
                                specialText: "Available Now",
                                detailLine1: "\(test.label1)\(test.label1Text)",
                                detailLine2: "\(test.label2)\(test.label2Text)",
                                showExtraSection: false,
                                fee: test.buttonText,
                                onButtonTap: {
                                    print("Fee button tapped for: \(test.title)")
                                },
                                isCheckboxSelectable: true,
                                initiallySelected: true,
                                onSelectionChange: { isSelected in
                                    if !isSelected { toggleSelection(test.id) }
                                }
                            )
                        }
                    }
                    .padding(.top, 20)
                }

                if !availableLabCards.isEmpty {
                    Text("Choose Your Lab Test")
                        .font(.app(.heading))
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(.top, 32)
                        .padding(.horizontal, 10)

                    VStack(spacing: 12) {
                        ForEach(availableLabCards) { test in
                            BloodTestCard(
                                image: TestDataset.imageName(for: test.title),
                                title: test.title,
                                specialText: "Available Now",
                                detailLine1: "\(test.label1)\(test.label1Text)",
                                detailLine2: "\(test.label2)\(test.label2Text)",
                                showExtraSection: false,
                                fee: test.buttonText,
                                onButtonTap: {
                                    print("Fee button tapped for: \(test.title)")
                                },
                                isCheckboxSelectable: true,
                                initiallySelected: false,
                                onSelectionChange: { isSelected in
                                    if isSelected { toggleSelection(test.id) }
                                }
                            )
                        }
                    }
                    .padding(.top, 20)
                }
            }
            .padding(.horizontal, 2)
            .padding(.bottom, 100)
            .animation(.spring(), value: selectedTests.isEmpty)
//            .navigationDestination(isPresented: $navigateToNext) {
//                LabTestDetailsView()
//            }

            VStack(spacing: 0) {
                LinearGradient(
                    colors: [Color(.systemBackground).opacity(0), Color(.systemBackground)],
                    startPoint: .top,
                    endPoint: .bottom
                )
                .frame(height: 24)

                HStack {
                    PrimaryButton(title: "Next", maxWidth: 160) {
                        navigateToNext = true
                    }
                    .disabled(selectedTests.isEmpty)
                    .opacity(selectedTests.isEmpty ? 0.5 : 1.0)
                }
                .frame(maxWidth: .infinity, alignment: .center)
                .padding(.vertical, 16)
                .background(Color(.systemBackground))
                
            }
            .animation(.spring(), value: selectedTests.isEmpty)
        }
    }

    private func toggleSelection(_ id: UUID) {
        withAnimation(.spring()) {
            if selectedTests.contains(id) {
                selectedTests.remove(id)
            } else {
                selectedTests.insert(id)
            }
        }
    }
}

struct LabDetailsView_Previews: PreviewProvider {
    static var previews: some View {
        NavigationStack {
            LabDetails(selectedCategory: "General Physician")
        }
    }
}
