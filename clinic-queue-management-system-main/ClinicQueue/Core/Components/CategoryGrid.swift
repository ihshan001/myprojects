//
//  CategoryGrid.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-08.
//
import SwiftUI

struct CategoryGrid: View {
    
    let items: [CategoryItem]
    @Binding var selectedCategories: Set<String>
    
    let columns = [
        GridItem(.flexible()),
        GridItem(.flexible()),
        GridItem(.flexible()),
        GridItem(.flexible())
    ]
    
    var body: some View {
        
        LazyVGrid(columns: columns, spacing: 20) {
            
            ForEach(items) { item in
                
                CategoryBoxGrid(
                    item: item,
                    isSelected: selectedCategories.contains(item.title),
                    action: {
                        if selectedCategories.contains(item.title) {
                            selectedCategories.remove(item.title)
                        } else {
                            selectedCategories.insert(item.title)
                        }
                    }
                )
            }
        }
    }
}
